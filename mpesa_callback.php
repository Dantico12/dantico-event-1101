<?php
// callback_url.php
require_once 'db.php';
require_once 'make_contribution.php';

$callbackData = file_get_contents('php://input');
$result = json_decode($callbackData, true);

error_log("M-Pesa STK Push Callback received: " . print_r($result, true));

try {
    // Extract STK Push specific response parameters
    $resultCode = $result['ResultCode'];
    $resultDesc = $result['ResultDesc'];
    $originatorConversationID = $result['OriginatorConversationID'];

    if ($resultCode === 0) {
        // Payment successful
        $transactionData = $result['ResultParameters']['ResultParameter'];
        $transactionAmount = 0;
        $mpesaReceiptNumber = '';
        $receiverPartyPublicName = '';

        foreach ($transactionData as $item) {
            switch ($item['Key']) {
                case 'TransactionAmount':
                    $transactionAmount = $item['Value'];
                    break;
                case 'TransactionReceipt':
                    $mpesaReceiptNumber = $item['Value'];
                    break;
                case 'ReceiverPartyPublicName':
                    $receiverPartyPublicName = $item['Value'];
                    break;
            }
        }

        // Update transaction status in database
        $stmt = $conn->prepare("UPDATE contributions SET 
            transaction_status = 'completed',
            mpesa_receipt_number = ?,
            transaction_id = ?,
            receiver_name = ?
            WHERE transaction_id = ? AND transaction_status = 'pending'");
            
        $stmt->bind_param("ssss", 
            $mpesaReceiptNumber, 
            $originatorConversationID,
            $receiverPartyPublicName,
            $originatorConversationID
        );
        $stmt->execute();
    } else {
        // Payment failed
        $stmt = $conn->prepare("UPDATE contributions SET 
            transaction_status = 'failed',
            mpesa_response = ?
            WHERE transaction_id = ?");
            
        $stmt->bind_param("ss", $resultDesc, $originatorConversationID);
        $stmt->execute();
    }
    
    // Return a success response for M-Pesa
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    error_log("STK Push Callback Processing Error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
