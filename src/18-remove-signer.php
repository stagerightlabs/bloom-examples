<?php

/**
 * Remove a signer from an account.
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
$bloom = Bloom::make(['debug' => true]);

// Ask the user to provide the signing key for the source account.
// Without this we won't be able to sign the transaction and
// it will be rejected when we submit it to the network.
$address = IO::prompt('Provide the address of the account to manage:');
if (empty($address)) {
    IO::error('You must provide the address of the account to manage.');
    exit(1);
}

// Load the details of the source account from horizon
$account = $bloom->account->retrieve($address);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

// Retrieve the value of the "high" operation threshold for this account
$highThresholdTarget = $account->getHighThreshold()->toNativeInt();

// Prepare to display the list of signers attached to this account
$accountSigners = array_map(function ($signerResource) {
    return [
        'address' => $signerResource->getKey(),
        'weight' => $signerResource->getWeight()->toNativeInt(),
    ];
}, $account->getSigners());

// We won't allow the transaction to proceed if there is only one signer
if (count($accountSigners) == 1) {
    IO::error("Account {$account->getAddress()} has only one signer.");
    exit(1);
}

// Ask the user to choose which signer should be removed
IO::info('Select the signer to remove:');
foreach ($accountSigners as $idx => $s) {
    IO::print("{$idx}. {$s['address']} - Weight {$s['weight']}");
}
$indexToBeRemoved = IO::prompt('Which signer should be removed?');
if ($indexToBeRemoved === '') {
    IO::error('You must specify a signer from the list.');
    exit(1);
}
$signerToBeRemoved = $bloom->keypair->fromAddress($accountSigners[$indexToBeRemoved]['address']);

// We won't proceed if the user has selected the master key
if ($signerToBeRemoved->getAddress() == $account->getAddress()) {
    IO::error("You probably don't want to remove the master signer");
    exit(1);
}

// Confirm the user action
IO::info('Remove signer details:');
IO::print(IO::color('Account:          ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('Signer to Remove: ', IO::COLOR_BLUE) . $signerToBeRemoved->getAddress());


if (IO::confirm('Do you wish to continue?')) {

    // Ask the user for all of the required signing keys.
    $signers = [];
    $cumulativeWeight = 0;
    foreach ($accountSigners as $s) {
        $seed = IO::prompt("Provide the secret key for signer {$s['address']}:");
        $signers[] = $bloom->keypair->fromSeed($seed);
        $cumulativeWeight = $s['weight'];

        if ($cumulativeWeight >= $highThresholdTarget) {
            break;
        }
    }

    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);
    $sequenceNumber = $account->getSequenceNumber();

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $sequenceNumber);

    // Prepare a 'set options' operation
    $setOptionsOp = $bloom->operation->setOptions(
        signer: $signerToBeRemoved->getWeightedSigner(0),
    );

    // Add the operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $setOptionsOp);

    // Wrap the transaction in a transaction envelope to prepare for submission.
    $envelope = $bloom->envelope->enclose($transaction);

    // Sign the envelope with the secret key of our key pair.
    foreach ($signers as $signingKeypair) {
        $envelope = $bloom->envelope->sign($envelope, $signingKeypair);
    }

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
    IO::success("https://stellar.expert/explorer/testnet/account/{$account->getAddress()}");
    IO::success("https://stellar.expert/explorer/testnet/tx/{$response->getHash()}");
} else {
    IO::error('Removing signer cancelled');
}

exit(0);
