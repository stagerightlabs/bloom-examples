<?php

/**
 * Manage account data entries with the manage data operation.
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
    exit(1);
}

// Load the details of the source account from horizon
$account = $bloom->account->retrieve($address);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

// Prompt for Asset A
$identifier = IO::prompt('What type of asset should be used for the first LP asset? Either "XLM" or "Code:ISSUER":');
if (empty($identifier)) {
    IO::error('You must provide an asset to be received.');
    exit(1);
}
$assetA = $bloom->asset->fromString($identifier);

// Prompt for Asset B
$identifier = IO::prompt('What type of asset should be used for the second LP asset? Either "XLM" or "Code:ISSUER":');
if (empty($identifier)) {
    IO::error('You must provide an asset to be received.');
    exit(1);
}
$assetB = $bloom->asset->fromString($identifier);

// Prompt for the maximum amount of Asset A to deposit
$amount = IO::prompt('How many shares should be withdrawn?');
if (empty($amount)) {
    IO::error('You must specify a withdraw amount.');
    exit(1);
}

// Prompt for min price
$minAmountA = IO::prompt("What is the minimum amount of {$assetA->getAssetCode()} you want to withdraw?");
if ($minAmountA === '') {
    IO::error("You must specify a minimum price.");
    exit(1);
}

// Prompt for max price
$minAmountB = IO::prompt("What is the minimum amount of {$assetB->getAssetCode()} you want to withdraw?");
if ($minAmountB === '') {
    IO::error("You must specify a maximum price.");
    exit(1);
}

// Confirm the user action
IO::info('Manage data details:');
IO::print(IO::color('Account:            ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Asset A:            ', IO::COLOR_BLUE) . $assetA->getCanonicalName());
IO::print(IO::color('Asset B:            ', IO::COLOR_BLUE) . $assetB->getCanonicalName());
IO::print(IO::color('Shares to withdraw: ', IO::COLOR_BLUE) . $amount);
IO::print(IO::color('Min Amount:         ', IO::COLOR_BLUE) . $minAmountA . $assetA->getAssetCode());
IO::print(IO::color('Min Amount:         ', IO::COLOR_BLUE) . $minAmountB . $assetB->getAssetCode());

if (IO::confirm('Do you wish to continue?')) {

    $pool = $bloom->liquidityPool->pool($assetA, $assetB);

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

    // Prepare a 'liquidity pool deposit' operation
    $liquidityPoolWithdrawOp = $bloom->operation->liquidityPoolWithdraw(
        poolId: $pool->getPoolId(),
        amount: $amount,
        minAmountA: $minAmountA,
        minAmountB: $minAmountB,
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $liquidityPoolWithdrawOp);

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
    IO::error('Manage data transaction cancelled');
}

exit(0);
