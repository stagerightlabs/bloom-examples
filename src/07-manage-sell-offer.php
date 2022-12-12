<?php

/**
 * Submit a sell offer with the ManageSellOffer operation.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make(['debug' => true]);

// Ask for the asset details that will make up the offer
IO::print("To submit a manage-sell-offer:");
$sellingAssetIdentifier = IO::prompt('Name the asset to be sold. Either "XLM" or "Code:ISSUER":');
if (empty($sellingAssetIdentifier)) {
    IO::error('You must provide an asset to be sold.');
    exit(1);
}
$sellingAsset = $bloom->asset->fromString($sellingAssetIdentifier);

$buyingAssetIdentifier = IO::prompt('Name the asset to be bought in exchange. Either "XLM" or "Code:ISSUER":');
if (empty($buyingAssetIdentifier)) {
    IO::error('You must provide an asset to be bought.');
    exit(1);
}
$buyingAsset = $bloom->asset->fromString($buyingAssetIdentifier);

$sellAmount = IO::prompt("How much {$sellingAsset->getAssetCode()} do you wish to sell?");
if (empty($sellAmount)) {
    IO::error("You must specify an amount of {$sellingAsset->getAssetCode()} to sell.");
    exit(1);
}

$price = IO::prompt("How much {$buyingAsset->getAssetCode()} do you want to receive per {$sellingAsset->getAssetCode()}?");
if (empty($price)) {
    IO::error("You must specify a price.");
    exit(1);
}

// Ask the user to provide the key for the source account to sign the transaction.
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

// Ensure the source account has a trustline for the purchased asset
if (!$account->getBalanceForAsset($buyingAsset)) {
    IO::error("Your account does not yet have a trustline for {$buyingAsset->getCanonicalName()}");
    exit(1);
}

// Confirm the user action
IO::info('Manage Buy offer Transaction details:');
IO::print(IO::color('Asset to be sold:      ', IO::COLOR_BLUE) . $sellingAsset->getCanonicalName());
IO::print(IO::color('Asset to be purchased: ', IO::COLOR_BLUE) . $buyingAsset->getCanonicalName());
IO::print(IO::color('Price:                 ', IO::COLOR_BLUE) . $price . $buyingAsset->getAssetCode() . '/1' . $sellingAsset->getAssetCode());

if (IO::confirm('Do you wish to continue?')) {
    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);
    $sequenceNumber = $account->getCurrentSequenceNumber();

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'manage sell offer' operation for inclusion in the transaction.
    $manageBuyOfferOp = $bloom->operation->manageSellOffer(
        sellingAsset: $sellingAsset,
        sellingAmount: $sellAmount,
        buyingAsset: $buyingAsset,
        price: $price,
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $manageBuyOfferOp);

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
    $result = $response->getResult();
    $manageSellOfferResult = $result->getOperationResultList()->get(0)->unwrap()->unwrap();

    if ($manageSellOfferResult->unwrap()->getOffersClaimed()->isNotEmpty()) {
        $claimOfferAtom = $manageSellOfferResult->unwrap()->getOffersClaimed()->get(0)->unwrap();
        $dexId = $claimOfferAtom->getOfferId()->toNativeString();
        IO::success('Offer accepted: https://stellar.expert/explorer/testnet/offer/{$dexId}?order=desc');
    } else {
        $offerEntry = $manageSellOfferResult->unwrap()->getOffer()->unwrap();
        $offerId = $offerEntry->getOfferId()->toNativeString();
        IO::success("Offer pending: https://stellar.expert/explorer/testnet/offer/{$offerId}");
    }

    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Manage sell offer cancelled');
}

exit(0);
