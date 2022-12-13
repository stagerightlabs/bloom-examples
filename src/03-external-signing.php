<?php

/**
 * Submit a payment transaction signed with a third party tool.
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
include_once '_io.php';

use StageRightLabs\Bloom\Bloom;
use StageRightLabs\Bloom\Envelope\TransactionEnvelope;
use StageRightLabs\Bloom\Horizon\Error as HorizonError;
use StageRightLabs\PhpXdr\XDR;

// When no config is specified Bloom will default to using the test network.
$bloom = Bloom::make();

// Ask for the destination address
$destination = IO::prompt('Provide a destination address for the payment:');
if (empty($destination)) {
    IO::error('You must provide a destination address.');
    exit(1);
}

$identifier = IO::prompt('What type of asset should be used? Either "XLM" or "Code:ISSUER":');
if (empty($identifier)) {
    IO::error('You must provide an asset to be received.');
    exit(1);
}
$asset = $bloom->asset->fromString($identifier);

// Ask for the amount of XLM to send to the new account
$amount = IO::prompt("How much {$asset->getAssetCode()} should be sent?");
if (empty($amount)) {
    IO::error('You must provide a transfer amount.');
    return exit(1);
}

// Ask the user to provide the signing key for the source account.
// Without this we won't be able to sign the transaction and
// it will be rejected when we submit it to the network.
$address = IO::prompt('Provide the address of the source account:');
if (empty($address)) {
    IO::error('You must provide a source account address.');
    return exit(1);
}

// Load the details of the source account from horizon
$keyPair = $bloom->keypair->fromAddress($address);
$account = $bloom->account->retrieve($keyPair);
if ($account instanceof HorizonError) {
    IO::error("The source account does not appear to be valid. ({$account->getTitle()})");
    exit(1);
}

// Ensure the source and destination accounts are not the same:
if ($account->getAddress() == $destination) {
    IO::error('The source and destination accounts are the same.');
    exit(1);
}

// Confirm the user action
IO::info('Payment Transaction details:');
IO::print(IO::color('Destination:     ', IO::COLOR_BLUE) . $destination);
IO::print(IO::color('Asset:           ', IO::COLOR_BLUE) . $asset->getCanonicalName());
IO::print(IO::color('Transfer Amount: ', IO::COLOR_BLUE) . $amount . ' ' . $asset->getAssetCode());
IO::print(IO::color('Drawn from:      ', IO::COLOR_BLUE) . $account->getAddress());

if (IO::confirm('Do you wish to continue?')) {
    // Increment the account sequence number
    $account = $bloom->account->incrementSequenceNumber($account);

    // Create the transaction object
    $transaction = $bloom->transaction->create($account, $account->getCurrentSequenceNumber());

    // Prepare a 'payment' operation for inclusion in the transaction.
    $paymentOp = $bloom->operation->payment(
        $destination,
        $asset,
        $amount,
        $account
    );

    // Add the payment operation to the transaction.
    $transaction = $bloom->transaction->addOperation($transaction, $paymentOp);

    // Wrap the transaction in a transaction envelope to prepare for submission.
    $envelope = $bloom->envelope->enclose($transaction);

    // Ask the user to sign the transaction envelope with a third party tool.
    IO::info('Transaction Envelope:');
    IO::print(' ');
    IO::print(XDR::fresh()->write($envelope)->toBase64());
    IO::print(' ');
    IO::info('Sign this payload with a third party tool, such as https://laboratory.stellar.org/#txsigner?network=test');

    // Request the signed payload from the user
    $xdr = IO::prompt('Enter the signed payload to proceed:');
    try {
        $envelope = XDR::fromBase64($xdr)->read(TransactionEnvelope::class);
    } catch (\Throwable $th) {
        IO::error('The returned payload is malformed and cannot be submitted.');
        exit(1);
    }

    // Submit the transaction envelope to Horizon
    IO::info('The transaction is being sent to Horizon');
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
    IO::error('External signing payment cancelled');
}

exit(0);
