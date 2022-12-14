<?php

/**
 * Send a payment.
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
    exit(1);
}

// Ask the user to provide the address for the account that will provide the funds
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
    $sequenceNumber = $account->getSequenceNumber();

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $sequenceNumber);

    // Prepare a 'payment' operation for inclusion in the transaction.
    //
    // We are specifying the amount as a string here; Bloom will
    // automatically 'de-scale' that into a stroop value. If we
    // provided an integer it would be interpreted as stroops.
    //
    // We are not required to include the source account with each
    // operation because it is also listed in the transaction
    // itself. We can still specify it here if we want to.
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
    IO::error('Payment cancelled');
}

exit(0);
