<?php

/**
 * Send an asset payment using the PathPaymentStrictSend operation.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask for the details that will make up the offer
IO::print("To submit a path payment strict send:");
$destination = IO::prompt('Provide an address to receive payment:');

$sendingAssetIdentifier = IO::prompt('Name the asset to be sent. Either "XLM" or "Code:ISSUER":');
if (empty($sendingAssetIdentifier)) {
    IO::error('You must provide an asset to be sold.');
    exit(1);
}
$sendingAsset = $bloom->asset->fromString($sendingAssetIdentifier);

$sendingAmount = IO::prompt("How much {$sendingAsset->getAssetCode()} would you like to send?");

$destinationAssetIdentifier = IO::prompt('Name the asset to be received. Either "XLM" or "Code:ISSUER":');
if (empty($destinationAssetIdentifier)) {
    IO::error('You must provide an asset to be received.');
    exit(1);
}
$destinationAsset = $bloom->asset->fromString($destinationAssetIdentifier);

$destinationMinimum = IO::prompt("What is the minimum amount of {$destinationAsset->getAssetCode()} that should be received?");

// Ask the user to provide the address of the source account
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

$destinationAccount = $bloom->account->retrieve($destination);

// Ensure the destination account has a trustline for the delivered asset
if (!$destinationAccount->getBalanceForAsset($destinationAsset)) {
    IO::error("The recipient does not have a trustline for {$destinationAsset->getCanonicalName()}");
    exit(1);
}

// Confirm the user action
IO::info('Path Payment Strict Send transaction details:');
IO::print(IO::color('Recipient:            ', IO::COLOR_BLUE) . $destinationAccount->getAddress());
IO::print(IO::color('Asset to be sent:     ', IO::COLOR_BLUE) . $sendingAsset->getCanonicalName());
IO::print(IO::color('Amount to send:       ', IO::COLOR_BLUE) . $sendingAmount);
IO::print(IO::color('Asset to be received: ', IO::COLOR_BLUE) . $destinationAsset->getCanonicalName());
IO::print(IO::color('Minimum to receive:   ', IO::COLOR_BLUE) . $destinationMinimum);

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

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'path payment strict send' operation for inclusion in the transaction.
    $pathPaymentStrictSendOp = $bloom->operation->pathPaymentStrictSend(
        sendingAsset: $sendingAsset,
        sendingAmount: $sendingAmount,
        destination: $destinationAccount,
        destinationAsset: $destinationAsset,
        destinationMinimum: $destinationMinimum,
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $pathPaymentStrictSendOp);

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
    IO::error('Path payment strict send transaction cancelled');
}

exit(0);
