<?php

/**
 * Submit a fee bump transaction that raises the fee of an existing transaction.
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

// Prompt the user for the inner transaction xdr string.
$xdr = IO::prompt('Provide the XDR of the envelope that will have its fee bumped:');
if (empty($xdr)) {
    IO::error('You must provide transaction envelope XDR.');
    exit(1);
}

try { 
    $envelope = $bloom->envelope->fromXdr(trim($xdr));
} catch (\Exception $e) {
    var_dump($e);
    IO::error('The XDR does not represent a valid transaction envelope.');
    exit(1);
}

// Prompt the user for the new fee amount
$fee = IO::prompt('What should the new fee be, in stroops?:');
if (empty($fee) || intval($fee) == 0) {
    IO::error('You must provide a valid fee amount');
    exit(1);
}

// Convert the fee to an integer so it is interpreted as stroops
$fee = intval($fee);

// Confirm the user action
IO::info('Manage data details:');
IO::print(IO::color('Fee Source: ', IO::COLOR_BLUE) . $account->getAddress());
IO::print(IO::color('New Fee:    ', IO::COLOR_BLUE) . $fee . ' stroops');
IO::print(IO::color('Inner Tx:   ', IO::COLOR_BLUE) . $xdr);

if (IO::confirm('Do you wish to continue?')) {

    // Ask the user to provide the signing key for the source account.
    $seed = IO::prompt("Provide the secret key for source account {$account->getAddress()}:");
    if (empty($seed)) {
        IO::error('You must provide a source account secret key for transaction signing.');
        exit(1);
    }
    $keypair = $bloom->keypair->fromSeed($seed);

    // Create the fee bump transaction
    $feeBumpTransaction = $bloom->transaction->createFeeBumpTransaction(
        envelope: $envelope,
        fee: $fee, 
        feeSource: $account
    );

    // Wrap the transaction in an envelope. 
    $feeBumpEnvelope = $bloom->envelope->enclose($feeBumpTransaction);

    // Sign the envelope with the secret key of our key pair.
    $feeBumpEnvelope = $bloom->envelope->sign($feeBumpEnvelope, $keypair);

    // Submit the transaction envelope to Horizon
    $response = $bloom->envelope->post($feeBumpEnvelope);

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
    IO::error('Fee bump transaction cancelled');
}

exit(0);
