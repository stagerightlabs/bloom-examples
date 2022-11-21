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
if (!$destination) {
    IO::error('You must provide a destination address.');
    exit(1);
}

// Ask for the amount of XLM to send to the new account
$amount = IO::prompt('How much XLM should be sent to the new account?');
if (!$amount) {
    IO::error('You must provide a transfer amount.');
    return exit(1);
}

$seed = IO::prompt('Provide the secret key of the source account to sign the transaction:');
if (!$seed) {
    IO::error('You must provide a source account secret key for transaction signing.');
    return exit(1);
}

// Load the details of the source account from horizon
$keyPair = $bloom->keypair->fromSeed($seed);
$account = $bloom->account->retrieve($keyPair);
if ($account instanceof HorizonError) {
    IO::error($account->getTitle() . ': ' . $account->getDetail());
    exit(1);
}
$account = $bloom->account->incrementSequenceNumber($account);
$sequenceNumber = $account->getCurrentSequenceNumber();

// Ensure the source and destination accounts are not the same:
if ($account->getAddress() == $destination) {
    IO::error('The source and destination accounts are the same.');
    exit(1);
}

// Confirm the user action
IO::info('Transaction details:');
IO::print(IO::color('New Account:     ', IO::COLOR_BLUE) . $destination);
IO::print(IO::color('Transfer Amount: ', IO::COLOR_BLUE) . $amount . ' XLM');
IO::print(IO::color('Drawn from:      ', IO::COLOR_BLUE) . $account->getAddress());

if (IO::confirm('Do you wish to continue?')) {
    // Create the new account by funding it from the source account.
    $transaction = $bloom->transaction->create($account, $sequenceNumber);
    $createAccountOp = $bloom->operation->createAccount($destination, $amount, $account);
    $transaction = $bloom->transaction->addOperation($transaction, $createAccountOp);
    $envelope = $bloom->envelope->enclose($transaction);
    $envelope = $bloom->envelope->sign($envelope, $keyPair);

    // Submit the transaction envelope to Horizon
    $response = $bloom->envelope->post($envelope);
    if ($response instanceof HorizonError) {
        IO::error($response->getMessage());
        exit(1);
    }

    IO::success("Operation complete.");
    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Account creation cancelled');
}

exit(0);
