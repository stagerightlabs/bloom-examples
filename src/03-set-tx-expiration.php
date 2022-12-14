<?php

/**
 * Set an expiration time on a transaction.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask for the destination address
$destination = IO::prompt('Provide a destination address for the payment:');
if (empty($destination)) {
    IO::error('You must provide a destination address.');
    exit(1);
}

// Ask for the amount of XLM to send to the new account
$amount = IO::prompt('How much XLM should be sent?');
if (empty($amount)) {
    IO::error('You must provide a transfer amount.');
    return exit(1);
}

$seconds = IO::prompt('How many seconds until the transaction expires?');
$seconds = intval($seconds);

// Ask the user to provide the signing key for the source account.
// Without this we won't be able to sign the transaction and
// it will be rejected when we submit it to the network.
$seed = IO::prompt('Provide the secret key of the source account to sign the transaction:');
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

// Ensure the source and destination accounts are not the same:
if ($account->getAddress() == $destination) {
    IO::error('The source and destination accounts are the same.');
    exit(1);
}

// Confirm the user action
IO::info('Payment Transaction details:');
IO::print(IO::color('Destination:     ', IO::COLOR_BLUE) . $destination);
IO::print(IO::color('Transfer Amount: ', IO::COLOR_BLUE) . $amount . ' XLM');
IO::print(IO::color('Drawn from:      ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Expires after:   ', IO::COLOR_BLUE) . $seconds . ' seconds');

if (IO::confirm('Do you wish to continue?')) {
    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Set the expiration time for the transaction. If no expiration time
    // is provided Bloom will default to a 1 hour validity window.
    $transaction = $bloom->transaction->setTimeout($transaction, $seconds);

    // Prepare a 'payment' operation for inclusion in the transaction.
    $paymentOp = $bloom->operation->payment(
        $destination,
        $bloom->asset->native(),
        $amount,
        $account
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $paymentOp);

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
    IO::error('Payment cancelled');
}

exit(0);
