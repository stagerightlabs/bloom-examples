<?php

/**
 * Create a trustline.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;
use StageRightLabs\Bloom\Primitives\Int64;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask the user to provide an asset code for the new asset.
$assetCode = IO::prompt('What asset code shall be given to the new asset? (case sensitive)');
if (empty($assetCode)) {
    IO::error('You must provide an asset code.');
    exit(1);
}
if (strlen($assetCode) > 12) {
    IO::error('Asset codes may not be longer than 12 characters.');
    exit(1);
}

$limit = IO::prompt('How much of the new asset should be issued? Leave blank for the max amount.');
if (empty($limit)) {
    $limit = Int64::max();
}

// Ask the user to provide the signing key for the distribution account.
$distributorSeed = IO::prompt('Provide the secret key of the distribution account to sign the transaction:');
if (empty($distributorSeed)) {
    IO::error('You must provide a distribution account secret key for transaction signing.');
    exit(1);
}
$distributorKeyPair = $bloom->keypair->fromSeed($distributorSeed);

// Ask the user to provide the signing key for the issuer account.
$issuerSeed = IO::prompt('Provide the secret key of the issuing account to sign the transaction:');
if (empty($issuerSeed)) {
    IO::error('You must provide an issuer account secret key for transaction signing.');
}

// Load the details of the issuing account from horizon
$issuingKeyPair = $bloom->keypair->fromSeed($issuerSeed);
$issuer = $bloom->account->retrieve($issuingKeyPair);
if ($issuer instanceof HorizonError) {
    IO::error("The issuing account does not appear to be valid. ({$issuer->getTitle()})");
    exit(1);
}

// Prepare the asset
$asset = $bloom->asset->build($assetCode, $issuer);

// Confirm the user action
IO::info('Asset Issuing Transaction details:');
IO::print(IO::color('Asset Code:  ', IO::COLOR_BLUE) . $assetCode);
IO::print(IO::color('Volume:      ', IO::COLOR_BLUE) . is_string($limit) ? $limit : $limit->toNativeString());
IO::print(IO::color('Issuer:      ', IO::COLOR_BLUE) . $issuer->getAddress());
IO::print(IO::color('Distributor: ', IO::COLOR_BLUE) . $distributorKeyPair->getAddress());

if (IO::confirm('Do you wish to continue?')) {
    // Increment the account sequence number
    $issuer = $bloom->account->incrementSequenceNumber($issuer);

    // Create the transaction object
    $transaction = $bloom->transaction->create($issuer, $issuer->getCurrentSequenceNumber());

    // Prepare a 'change trust' operation for inclusion in the transaction.
    $changeTrustOp = $bloom->operation->changeTrust(
        line: $asset,
        source: $distributorKeyPair,
    );

    // Add the change trust operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $changeTrustOp);

    // Prepare a payment operation for inclusion in the transaction
    // the issuing account will pay the distribution account with the new asset
    $paymentOp = $bloom->operation->payment(
        destination: $distributorKeyPair,
        asset: $asset,
        amount: $limit,
        source: $issuingKeyPair,
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $paymentOp);

    // Wrap the transaction in a transaction envelope to prepare for submission.
    $envelope = $bloom->envelope->enclose($transaction);

    // Sign the envelope with the secret keys of the two key pairs
    $envelope = $bloom->envelope->sign($envelope, $issuer);
    $envelope = $bloom->envelope->sign($envelope, $distributorKeyPair);

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
    IO::success("https://stellar.expert/explorer/testnet/asset/{$assetCode}-{$issuer->getAddress()}");
    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Asset issuing cancelled');
}

exit(0);
