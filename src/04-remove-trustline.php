<?php

/**
 * Remove a payment.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide the signing key for the source account.
// We will use this to fetch balance details for the account.
$seed = IO::prompt('Provide the secret key of the source account:');
if (empty($seed)) {
    IO::error('You must provide a secret key.');
    return exit(1);
}

// Load the details of the source account from horizon
$keyPair = $bloom->keypair->fromSeed($seed);
$account = $bloom->account->retrieve($keyPair);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

$trustlines = [];
$count = 1;
foreach ($account->getBalances() as $balance) {
    if (!$balance->isNativeAsset()) {
        $trustlines[$count] = $balance->getCanonicalAssetName();
        $count++;
    }
}

if (!empty($trustlines)) {
    IO::print('Found the following trustlines:');
    foreach ($trustlines as $idx => $name) {
        IO::print("{$idx}. {$name}");
    }
} else {
    IO::error("Account {$account->getAddress()} has no trustlines.");
    exit(0);
}

$indexToBeRemoved = IO::prompt('Which trustline should be removed?');
if (empty($indexToBeRemoved)) {
    IO::error('You must specify a trustline to remove.');
    return exit(1);
}

// Confirm the user action
IO::info('Trustline Removal Transaction details:');
IO::print(IO::color('Asset to be removed:        ', IO::COLOR_BLUE) . $trustlines[$indexToBeRemoved]);
IO::print(IO::color('Account Removing Trustline: ', IO::COLOR_BLUE) . $account->getAddress());

if (IO::confirm('Do you wish to continue?')) {

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);
    $sequenceNumber = $account->getCurrentSequenceNumber();

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'change trust' operation for inclusion in the transaction.
    $changeTrustOp = $bloom->operation->changeTrust(
        line: $bloom->asset->fromString($trustlines[$indexToBeRemoved]),
        limit: 0
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $changeTrustOp);

    // Wrap the transaction in a transaction envelope to prepare for submission.
    $envelope = $bloom->envelope->enclose($transaction);

    // Sign the envelope with the secret key of our key pair.
    $envelope = $bloom->envelope->sign($envelope, $keyPair);

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
    IO::success("Trustline for {$trustlines[$indexToBeRemoved]} has been removed.");
    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Trustline creation cancelled');
}

exit(0);
