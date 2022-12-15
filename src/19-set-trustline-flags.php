<?php

/**
 * Set trustline flags for an asset.
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
$address = IO::prompt('Provide the address of the issuing account:');
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

// Prompt for the trustline account address
$trustorAddress = IO::prompt('Provide the address of the trustline account to manage:');
if (empty($trustorAddress)) {
    IO::error('You must provide a source account secret key for transaction signing.');
    return exit(1);
}

$identifier = IO::prompt('Which trustline asset will be managed? ["Code:ISSUER"]:');
if (empty($identifier)) {
    IO::error('You must provide an asset to be managed.');
    exit(1);
}
$asset = $bloom->asset->fromString($identifier);

if ($asset->getIssuerAddress() != $account->getAddress()) {
    IO::error('You can only set trustline flags for assets issued from your account');
    exit(1);
}

// Flags
$authorized = null;
$authorizedToMaintainLiabilities = null;
$clawbackEnabled = null;
IO::print('Select the flag to set:');
IO::print('1. Authorized (true)');
IO::print('2. Authorized (false)');
IO::print('3. Authorized To Maintain Liabilities (true)');
IO::print('4. Authorized To Maintain Liabilities (false)');
IO::print('5. Clawback Enabled (false)');
switch (IO::prompt('Which flag will be set?')) {
    case '1':
        $authorized = true;
        break;

    case '2':
        $authorized = false;
        break;

    case '3':
        $authorizedToMaintainLiabilities = true;
        break;

    case '4':
        $authorizedToMaintainLiabilities = false;
        break;

    case '5':
        $clawbackEnabled = false;
        break;

    default:
        IO::error('Invalid flag selection');
        exit(1);
        break;
}

// Confirm the user action
IO::info('Set trustline flag details:');
IO::print(IO::color('Account: ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Asset:   ', IO::COLOR_BLUE) . $asset->getAssetCode());
if (!is_null($authorized)) {
    IO::print(IO::color('Flag:     ', IO::COLOR_BLUE) . 'Authorized - ' . ($authorized ? 'true' : 'false'));
}
if (!is_null($authorizedToMaintainLiabilities)) {
    IO::print(IO::color('Flag:     ', IO::COLOR_BLUE) . 'Authorized to maintain liabilities- ' . ($authorizedToMaintainLiabilities ? 'true' : 'false'));
}
if (!is_null($clawbackEnabled)) {
    IO::print(IO::color('Flag:     ', IO::COLOR_BLUE) . 'Clawback Enabled - false');
}

if (IO::confirm('Do you wish to continue?')) {

    // Prompt for the source account seed:
    $seed = IO::prompt("Provide the secret key for issuing account {$account->getAddress()}:");
    if (empty($seed)) {
        IO::error('You must provide a source account secret key for transaction signing.');
        return exit(1);
    }
    $keypair = $bloom->keypair->fromSeed($seed);

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'set trustline flags' operation
    $setTrustlineFlagsOp = $bloom->operation->setTrustLineFlags(
        trustor: $trustorAddress,
        asset: $asset,
        authorized: $authorized,
        authorizedToMaintainLiabilities: $authorizedToMaintainLiabilities,
        clawbackEnabled: $clawbackEnabled,
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $setTrustlineFlagsOp);

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
    IO::error('Set trustline flags transaction cancelled');
}

exit(0);
