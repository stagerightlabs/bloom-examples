<?php

/**
 * Create a new account that has its reserves sponsored by another account
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide the address of the source account
$sponsorAddress = IO::prompt('Provide the address of the sponsoring account:');
if (empty($sponsorAddress)) {
    IO::error('You must provide a source account address.');
    exit(1);
}

// Load the details of the source account from horizon
$sponsorAccount = $bloom->account->retrieve($sponsorAddress);
if ($sponsorAccount instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$sponsorAccount->getTitle()})");
    exit(1);
}

// Generate a new random keypair to be sponsored
$sponsoredKeypair = $bloom->keypair->generate();

// Confirm the user action
IO::info('Reserve sponsoring details:');
IO::print(IO::color('Sponsor:     ', IO::COLOR_BLUE) . $sponsorAccount->getAddress());
IO::print(IO::color('New Account: ', IO::COLOR_BLUE) . $sponsoredKeypair->getAddress());
IO::print(IO::color('New Seed:    ', IO::COLOR_BLUE) . $sponsoredKeypair->getSeed());

if (IO::confirm('Do you wish to continue?')) {

    // Ask the user to provide the signing key for the source account.
    $seed = IO::prompt("Provide the secret key for source account {$sponsorAccount->getAddress()}:");
    if (empty($seed)) {
        IO::error('You must provide a source account secret key for transaction signing.');
        exit(1);
    }
    $sponsorKeypair = $bloom->keypair->fromSeed($seed);

    // Increment the account sequence number
    $sponsorAccount = $bloom->account->incrementSequenceNumber($sponsorAccount);
    $sequenceNumber = $sponsorAccount->getSequenceNumber();

    // Create the transaction object
    $transaction = $bloom->transaction->create($sponsorAccount, $sequenceNumber);

    // Create a 'begin sponsoring future reserves' operation
    $beginSponsoringFutureReservesOp = $bloom->operation->beginSponsoringFutureReserves(
        sponsoredId: $sponsoredKeypair
    );
    $transaction = $bloom->transaction->addOperation($transaction, $beginSponsoringFutureReservesOp);

    // Create a 'create account' operation for the new account
    $createAccountOp = $bloom->operation->createAccount(
        destination: $sponsoredKeypair,
        startingBalance: 0,
    );
    $transaction = $bloom->transaction->addOperation($transaction, $createAccountOp);

    // Create an 'end sponsoring future reserves' operation to close the sandwich
    // Using the sponsored keypair as the source account ensures that the
    // sponsored account will have to give permission by signing.
    $endSponsoringFutureReservesOp = $bloom->operation->endSponsoringFutureReserves(
        source: $sponsoredKeypair,
    );
    $transaction = $bloom->transaction->addOperation($transaction, $endSponsoringFutureReservesOp);

    // Wrap the transaction in a transaction envelope to prepare for submission.
    $envelope = $bloom->envelope->enclose($transaction);

    // Sign the envelope with both accounts.
    $envelope = $bloom->envelope->sign($envelope, $sponsorKeypair);
    $envelope = $bloom->envelope->sign($envelope, $sponsoredKeypair);

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
    IO::error('Sponsoring Reserves transaction cancelled');
}

exit(0);
