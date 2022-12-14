<?php

/**
 * Manage account data entries with the manage data operation.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide the signing key for the source account.
// Without this we won't be able to sign the transaction and
// it will be rejected when we submit it to the network.
$seed = IO::prompt('Provide the secret key of the source account to manage:');
if (empty($seed)) {
    IO::error('You must provide a source account secret key for transaction signing.');
    return exit(1);
}

// Load the details of the source account from horizon
$keyPair = $bloom->keypair->fromSeed($seed);
$account = $bloom->account->retrieve($keyPair);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

if (!empty($account->getData())) {
    IO::info("Existing data entries for {$account->getAddress()}:");
    foreach ($account->getData() as $key => $value) {
        IO::print(IO::color($key . ':', IO::COLOR_BLUE) . ' ' . base64_decode($value));
    }
}

// Prompt the user for the name of the data entry to manage.
$name = IO::prompt('Provide the name of the data entry to manage:');
if (empty($name)) {
    IO::error('You must provide a data entry name.');
    exit(1);
}
if (strlen($name) > 64) {
    IO::error('The data entry name cannot be longer than 64 characters');
    exit(1);
}

// Prompt the user for the value of the data entry
$value = IO::prompt("What should the value of the '{$name}' entry be? Leave blank to unset");
if (strlen($value) > 64) {
    IO::error('The data value cannot be longer than 64 characters');
    exit(1);
}
if (empty($value)) {
    $value = null;
}

// Confirm the user action
IO::info('Manage data details:');
IO::print(IO::color('Account:    ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Data name:  ', IO::COLOR_BLUE) . $name);
IO::print(IO::color('Data value: ', IO::COLOR_BLUE) . (is_null($value) ? '[REMOVE]' : $value));

if (IO::confirm('Do you wish to continue?')) {

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'manage data' operation
    $manageDataOp = $bloom->operation->manageData(
        name: $name,
        value: $value,
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $manageDataOp);

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
    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Manage data transaction cancelled');
}

exit(0);
