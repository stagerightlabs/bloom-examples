<?php

/**
 * Submit a buy offer with the ManageBuyOffer operation.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask for the asset details that will make up the offer
IO::print("To submit a manage-buy-offer:");
$buyingAssetIdentifier = IO::prompt('Name the asset to be bought. Either "XLM" or "Code:ISSUER":');
if (empty($buyingAssetIdentifier)) {
    IO::error('You must provide an asset to be bought.');
    exit(1);
}
$buyingAsset = $bloom->asset->fromString($buyingAssetIdentifier);

$sellingAssetIdentifier = IO::prompt('Name the asset to be sold in exchange. Either "XLM" or "Code:ISSUER":');
if (empty($sellingAssetIdentifier)) {
    IO::error('You must provide an asset to be sold.');
    exit(1);
}
$sellingAsset = $bloom->asset->fromString($sellingAssetIdentifier);

$buyAmount = IO::prompt("How much {$buyingAsset->getAssetCode()} do you wish to purchase?");
if (empty($buyAmount)) {
    IO::error("You must specify an amount of {$buyingAsset->getAssetCode()} to purchase.");
    exit(1);
}

$price = IO::prompt("How much {$sellingAsset->getAssetCode()} do you want to pay per {$buyingAsset->getAssetCode()}?");
if (empty($price)) {
    IO::error("You must specify a price.");
    exit(1);
}

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

// Ensure the source account has a trustline for the purchased asset
if (!$account->getBalanceForAsset($buyingAsset)) {
    IO::error("Your account does not yet have a trustline for {$buyingAsset->getCanonicalName()}");
    exit(1);
}

// Confirm the user action
IO::info('Manage Buy offer Transaction details:');
IO::print(IO::color('Asset to be purchased: ', IO::COLOR_BLUE) . $buyingAsset->getCanonicalName());
IO::print(IO::color('Asset to be sold:      ', IO::COLOR_BLUE) . $sellingAsset->getCanonicalName());
IO::print(IO::color('Price:                 ', IO::COLOR_BLUE) . $price . $sellingAsset->getAssetCode() . '/1' . $buyingAsset->getAssetCode());

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

    // Prepare a 'manage buy offer' operation for inclusion in the transaction.
    $manageBuyOfferOp = $bloom->operation->manageBuyOffer(
        sellingAsset: $sellingAsset,
        buyingAsset: $buyingAsset,
        buyingAmount: $buyAmount,
        price: $price,
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $manageBuyOfferOp);

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
    $result = $response->getResult();
    $manageBuyOfferResult = $result->getOperationResultList()->get(0)->unwrap()->unwrap();

    if ($manageBuyOfferResult->unwrap()->getOffersClaimed()->isNotEmpty()) {
        $claimOfferAtom = $manageBuyOfferResult->unwrap()->getOffersClaimed()->get(0)->unwrap();
        $dexId = $claimOfferAtom->getOfferId()->toNativeString();
        IO::success('Offer accepted: https://stellar.expert/explorer/testnet/offer/{$dexId}?order=desc');
    } else {
        $offerEntry = $manageBuyOfferResult->unwrap()->getOffer()->unwrap();
        $offerId = $offerEntry->getOfferId()->toNativeString();
        IO::success("Offer pending: https://stellar.expert/explorer/testnet/offer/{$offerId}");
    }

    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Manage buy offer cancelled');
}

exit(0);
