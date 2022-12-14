<?php

/**
 * Claim a claimable balance.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide the address of the source account
$address = IO::prompt('Provide the address of the account that will claim the balance:');
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

// Ask the user to provide the claimable balance ID.
// Eventually this will be replaced with a method to fetch the balance ID.
$balanceId = IO::prompt('What is the balance ID to be claimed?:');
if (empty($balanceId)) {
    IO::error('You must provide an claimable balance ID');
    exit(1);
}

// Confirm the user action
IO::info('Claim claimable balance details:');
IO::print(IO::color('Claimant:   ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Balance ID: ', IO::COLOR_BLUE) . $balanceId);

if (IO::confirm('Do you wish to continue?')) {

    // Ask the user to provide the signing key for the source account.
    $seed = IO::prompt("Provide the secret key for account {$account->getAddress()}:");
    if (empty($seed)) {
        IO::error('You must provide a secret key for transaction signing.');
        exit(1);
    }
    $keypair = $bloom->keypair->fromSeed($seed);

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);
    $sequenceNumber = $account->getSequenceNumber();

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $sequenceNumber);

    // Prepare a 'create claimable balance' operation
    $claimClaimableBalanceOp = $bloom->operation->claimClaimableBalance(
        balanceId: $balanceId,
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $claimClaimableBalanceOp);

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
    IO::error('Claim claimable balance transaction cancelled');
}

exit(0);
