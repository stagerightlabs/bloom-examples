<?php

/**
 * Add a signer to an account.
 *
 * Warning: If you are not careful you can be permanently locked out of an account:
 * https://developers.stellar.org/docs/encyclopedia/signatures-multisig
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
$address = IO::prompt('Provide the address of the account to manage:');
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

// Prompt the user for the address of the new signer
$newSignerAddress = IO::prompt("What is the address of the new signer?");
if (empty($newSignerAddress)) {
    IO::error('You must provide details about the new signer.');
    exit(1);
}
$newSigner = $bloom->keypair->fromAddress($newSignerAddress);

// Prompt the user for the weight of the new signer.
$weight = IO::prompt("What weight should be given to the new signer?");
if (empty($newSignerAddress)) {
    IO::error('You must provide a weight.');
    exit(1);
}
$weight = intval($weight);

// Confirm the user action
IO::info('Add signer details:');
IO::print(IO::color('Account:    ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('New Signer: ', IO::COLOR_BLUE) . $newSigner->getAddress());
IO::print(IO::color('Weight:     ', IO::COLOR_BLUE) . $weight);

if (IO::confirm('Do you wish to continue?')) {

    // Prompt for the master key seed:
    $seed = IO::prompt("Provide the secret key for account {$account->getAddress()}:");
    $keypair = $bloom->keypair->fromSeed($seed);

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'set options' operation
    $setOptionsOp = $bloom->operation->setOptions(
        signer: $newSigner->getWeightedSigner($weight),
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $setOptionsOp);

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
    IO::error('Adding signer cancelled');
}

exit(0);
