<?php

/**
 * Bump the sequence number for an account
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
    return exit(1);
}

// Load the details of the source account from horizon
$account = $bloom->account->retrieve($address);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

// Prompt the user for the amount to add to the sequence number
$increment = IO::prompt('How much should the account sequence number be increased? (int):');
if (empty($increment)) {
    IO::error('You must a bump increment.');
    exit(1);
}
$increment = intval($increment);
if (!is_int($increment)) {
    IO::error('You have entered an invalid increment amount');
    exit(1);
}

// Confirm the user action
IO::info('Bump sequence details:');
IO::print(IO::color('Account:                   ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Sequence number increment: ', IO::COLOR_BLUE) . $increment);

if (IO::confirm('Do you wish to continue?')) {

    // Ask the user to provide the signing key for the source account.
    $seed = IO::prompt("Provide the secret key for source account {$account->getAddress()}:");
    if (empty($seed)) {
        IO::error('You must provide a source account secret key for transaction signing.');
        return exit(1);
    }
    $keypair = $bloom->keypair->fromSeed($seed);

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'bump sequence' operation
    $bumpSequenceOp = $bloom->operation->bumpSequence(
        bumpTo: $account->getCurrentSequenceNumber()->toNativeInt() + $increment,
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $bumpSequenceOp);

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
    IO::error('Bump sequence transaction cancelled');
}

exit(0);
