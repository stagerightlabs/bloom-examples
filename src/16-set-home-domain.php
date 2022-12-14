<?php

/**
 * Set the home domain for an account with the set options operation.
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

// Prompt the user for the value of the data entry
$homeDomain = IO::prompt("What should the new home domain be? Leave blank for removal.");
if (strlen($homeDomain) > 32) {
    IO::error('The data value cannot be longer than 32 characters');
    exit(1);
}

// Confirm the user action
IO::info('Set options (home domain) details:');
IO::print(IO::color('Account:     ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Home domain: ', IO::COLOR_BLUE) . (empty($homeDomain) ? '[REMOVE]' : $homeDomain));

if (IO::confirm('Do you wish to continue?')) {

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'set options' operation
    $setOptions = $bloom->operation->setOptions(
        homeDomain: $homeDomain,
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $setOptions);

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
    IO::error('Set options transaction cancelled');
}

exit(0);
