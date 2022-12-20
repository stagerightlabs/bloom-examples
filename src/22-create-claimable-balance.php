<?php

/**
 * Create a claimable balance.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide the address of the source account
$address = IO::prompt('Provide the address of the source account:');
if (empty($address)) {
    IO::error('You must provide a source account address.');
    exit(1);
}

// Load the details of the source account from horizon
$account = $bloom->account->retrieve($address);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

$claimantAddress = IO::prompt('Provide the address of the claimable balance recipient:');
if (empty($claimantAddress)) {
    IO::error('You must provide a recipient address');
    exit(1);
}
$claimant = $bloom->claimableBalance->claimant($claimantAddress);
$accountClaimant = $bloom->claimableBalance->claimant($account);

$identifier = IO::prompt('What type of asset should be used? Either "XLM" or "Code:ISSUER":');
if (empty($identifier)) {
    IO::error('You must provide an asset to be claimed.');
    exit(1);
}
$asset = $bloom->asset->fromString($identifier);

// Ask for the amount of XLM to send to the new account
$amount = IO::prompt("How much {$asset->getAssetCode()} should be claimable?");
if (empty($amount)) {
    IO::error('You must provide an amount for the claimable balance.');
    exit(1);
}

// Confirm the user action
IO::info('Create claimable balance details:');
IO::print(IO::color('Claimant: ', IO::COLOR_BLUE) . $claimant->getAddress());
IO::print(IO::color('Asset:    ', IO::COLOR_BLUE) . $asset->getCanonicalName());
IO::print(IO::color('Amount:   ', IO::COLOR_BLUE) . $amount . ' ' . $asset->getAssetCode());
IO::print(IO::color('Source:   ', IO::COLOR_BLUE) . $account->getAddress());

if (IO::confirm('Do you wish to continue?')) {

    // Ask the user to provide the signing key for the source account.
    $seed = IO::prompt("Provide the secret key for source account {$account->getAddress()}:");
    if (empty($seed)) {
        IO::error('You must provide a source account secret key for transaction signing.');
        exit(1);
    }
    $keypair = $bloom->keypair->fromSeed($seed);

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'create claimable balance' operation
    $createClaimableBalanceOp = $bloom->operation->createClaimableBalance(
        asset: $asset,
        amount: $amount,
        claimants: [$claimant, $accountClaimant],
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $createClaimableBalanceOp);

    // Wrap the transaction in a transaction envelope to prepare for submission.
    $envelope = $bloom->envelope->enclose($transaction);

    // Sign the envelope with the secret key of our key pair.
    $envelope = $bloom->envelope->sign($envelope, $keypair);

    // Submit the transaction envelope to Horizon
    $response = $bloom->envelope->post($envelope);

    // An error response indicates that something went wrong.
    if ($response instanceof HorizonError) {
        IO::error($response->getTitle());
        $result = $response->getResult();
        if ($result->getOperationResultList()->isNotEmpty()) {
            foreach ($result->getOperationResultList() as $operationResult) {
                IO::error($operationResult->getErrorMessage());
            }
        } else {
            IO::error($result->getErrorMessage());
        }

        exit(1);
    }

    // Otherwise we are good to go.
    IO::success("Transaction complete.");
    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Create claimable balance transaction cancelled');
}

exit(0);
