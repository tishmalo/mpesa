<?php

namespace Tish\LaravelMpesa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tish\LaravelMpesa\MpesaService;
use Tish\LaravelMpesa\Models\MpesaTransaction;
use Tish\LaravelMpesa\Events\PaymentCompleted;
use Tish\LaravelMpesa\Events\PaymentFailed;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MpesaController extends Controller
{
    protected $mpesa;

    public function __construct(MpesaService $mpesa)
    {
        $this->mpesa = $mpesa;
    }

    public function stkPush(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'account_reference' => 'required|string',
            'transaction_desc' => 'required|string',
        ]);

        try {
            $response = $this->mpesa->stkPush(
                $request->phone,
                $request->amount,
                $request->account_reference,
                $request->transaction_desc
            );

            // Store initial transaction record
            if (isset($response['CheckoutRequestID'])) {
                MpesaTransaction::create([
                    'checkout_request_id' => $response['CheckoutRequestID'],
                    'merchant_request_id' => $response['MerchantRequestID'],
                    'phone_number' => $request->phone,
                    'amount' => $request->amount,
                    'account_reference' => $request->account_reference,
                    'transaction_desc' => $request->transaction_desc,
                    'status' => 'pending',
                    'response_code' => $response['ResponseCode'],
                    'response_description' => $response['ResponseDescription'],
                ]);

                Log::info('M-Pesa STK Push initiated', [
                    'checkout_request_id' => $response['CheckoutRequestID'],
                    'account_reference' => $request->account_reference,
                    'amount' => $request->amount
                ]);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('M-Pesa STK Push failed', [
                'error' => $e->getMessage(),
                'account_reference' => $request->account_reference ?? null
            ]);
            
            return response()->json([
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    public function callback(Request $request)
    {
        // Log all incoming callback data
        Log::info('M-Pesa Callback Received', [
            'full_payload' => $request->all(),
            'headers' => $request->headers->all(),
            'ip' => $request->ip()
        ]);
        
        try {
            // Extract the callback data
            $callbackData = $request->all();
            
            if (!isset($callbackData['Body']['stkCallback'])) {
                Log::error('Invalid M-Pesa callback structure', $callbackData);
                return $this->callbackResponse('Invalid callback structure');
            }

            $stkCallback = $callbackData['Body']['stkCallback'];
            $checkoutRequestId = $stkCallback['CheckoutRequestID'];
            $merchantRequestId = $stkCallback['MerchantRequestID'];
            $resultCode = $stkCallback['ResultCode'];
            $resultDesc = $stkCallback['ResultDesc'];
            
            // Find the transaction record
            $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();
            
            if (!$transaction) {
                Log::error('Transaction not found for CheckoutRequestID', [
                    'checkout_request_id' => $checkoutRequestId,
                    'merchant_request_id' => $merchantRequestId
                ]);
                return $this->callbackResponse('Transaction not found');
            }
            
            // Update transaction with callback data
            $updateData = [
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'status' => $resultCode == 0 ? 'completed' : 'failed',
            ];

            if ($resultCode == 0) {
                // Payment successful - extract metadata
                if (isset($stkCallback['CallbackMetadata']['Item'])) {
                    $callbackMetadata = $stkCallback['CallbackMetadata']['Item'];
                    $metadata = [];
                    
                    foreach ($callbackMetadata as $item) {
                        $metadata[$item['Name']] = $item['Value'] ?? null;
                    }
                    
                    // Update transaction with payment details
                    $updateData = array_merge($updateData, [
                        'mpesa_receipt_number' => $metadata['MpesaReceiptNumber'] ?? null,
                        'transaction_date' => isset($metadata['TransactionDate']) ? 
                            Carbon::createFromFormat('YmdHis', $metadata['TransactionDate']) : null,
                        'phone_number' => $metadata['PhoneNumber'] ?? $transaction->phone_number,
                        'amount' => $metadata['Amount'] ?? $transaction->amount,
                        'callback_metadata' => json_encode($metadata),
                    ]);

                    Log::info('Payment completed successfully', [
                        'transaction_id' => $transaction->id,
                        'checkout_request_id' => $checkoutRequestId,
                        'receipt_number' => $metadata['MpesaReceiptNumber'] ?? 'N/A',
                        'amount' => $metadata['Amount'] ?? 'N/A',
                        'account_reference' => $transaction->account_reference
                    ]);
                }
                
                $transaction->update($updateData);
                
                // Fire payment completed event
                event(new PaymentCompleted($transaction->fresh()));
                
            } else {
                // Payment failed
                $transaction->update($updateData);
                
                Log::warning('Payment failed', [
                    'transaction_id' => $transaction->id,
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'account_reference' => $transaction->account_reference
                ]);
                
                // Fire payment failed event
                event(new PaymentFailed($transaction->fresh()));
            }
            
        } catch (\Exception $e) {
            Log::error('Error processing M-Pesa callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'callback_data' => $request->all()
            ]);
        }
        
        return $this->callbackResponse();
    }
    
    public function stkQuery(Request $request)
    {
        $request->validate([
            'checkout_request_id' => 'required|string',
        ]);
        
        try {
            $response = $this->mpesa->stkQuery($request->checkout_request_id);
            
            // Update transaction status based on query result
            $transaction = MpesaTransaction::where('checkout_request_id', $request->checkout_request_id)->first();
            if ($transaction && isset($response['ResultCode'])) {
                $transaction->update([
                    'result_code' => $response['ResultCode'],
                    'result_desc' => $response['ResultDesc'],
                    'status' => $response['ResultCode'] == 0 ? 'completed' : 'failed',
                ]);
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function getTransactionStatus($checkoutRequestId)
    {
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();
        
        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }
        
        return response()->json([
            'checkout_request_id' => $transaction->checkout_request_id,
            'status' => $transaction->status,
            'result_code' => $transaction->result_code,
            'result_desc' => $transaction->result_desc,
            'mpesa_receipt_number' => $transaction->mpesa_receipt_number,
            'transaction_date' => $transaction->transaction_date,
            'amount' => $transaction->amount,
            'phone_number' => $transaction->phone_number,
            'account_reference' => $transaction->account_reference,
        ]);
    }
    
    /**
     * Standard callback response that M-Pesa expects
     */
    private function callbackResponse($message = 'Callback processed successfully')
    {
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => $message
        ]);
    }
}