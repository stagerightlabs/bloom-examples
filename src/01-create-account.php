<?php

/**
 * Create and fund a Stellar account.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask for the destination address
$destination = IO::prompt('Provide an address for the account to be created:');
if (empty($destination)) {
    IO::error('You must provide a destination address.');
    exit(1);
}

// Ask for the amount of XLM to send to the new account
$amount = IO::prompt('How much XLM should be sent to the new account?');
if (empty($amount)) {
    IO::error('You must provide a transfer amount.');
    return exit(1);
}

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
IO::info('Account Creation Transaction details:');
IO::print(IO::color('New Account:     ', IO::COLOR_BLUE) . $destination);
IO::print(IO::color('Transfer Amount: ', IO::COLOR_BLUE) . $amount . ' XLM');
IO::print(IO::color('Drawn from:      ', IO::COLOR_BLUE) . $account->getAddress());

if (IO::confirm('Do you wish to continue?')) {
    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);
    $sequenceNumber = $account->getCurrentSequenceNumber();

    // We will now create the new account by funding it from the source account.

    // First determine the maximum transaction fee we are willing to pay.
    // We won't necessarily be charged this amount; the network will
    // only charge the minimum required fee based on network traffic.
    //
    // See more here:
    // https://developers.stellar.org/docs/encyclopedia/fees-surge-pricing-fee-strategies#network-fees-on-stellar
    //
    // The minimum fee is the base fee (100 stroops) * the number of operations
    // in our transaction.
    $fee = 100; // 100 stroops * 1 operation

    // Now we will create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber(), $fee);

    // Prepare a 'create account' operation for inclusion in the transaction.
    //
    // It requires the public address of the account to be created and
    // some amount of XLM to transfer to the new account to create it.
    // We are specifying that amount as a string here; Bloom will
    // automatically 'de-scale' that into a stroop value. If we
    // provided an integer it would be interpreted as stroops.
    //
    // We are not required to include the source account with each
    // operation because it is also listed in the transaction
    // itself. We can still specify it here if we want to.
    $createAccountOp = $bloom->operation->createAccount($destination, $amount, $account);

    // Add the create-account operation to the transaction.
    //
    // Note that all Bloom objects are immutable by default; The
    // transaction instance we got back is a new PHP object
    // that has its own separate space in memory.
    $transaction = $bloom->transaction->addOperation($transaction, $createAccountOp);

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
    IO::error('Account creation cancelled');
}

exit(0);
