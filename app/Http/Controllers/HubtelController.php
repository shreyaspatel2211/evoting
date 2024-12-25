<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\CallbackRequest;
use App\Models\Plan;
use App\Models\Loan;
use App\Models\LoanRequest;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\WithdrawlRequest;
use App\Models\Candidate;
use App\Models\Event;

class HubtelController extends Controller
{
    public function hubtelUSSD(Request $request, $company_id){
        $caseType = null;
        if($request->Type == 'Response'){
            if ($request->Sequence == 2) {
                switch ($request->Message) {
                    case '3':
                        $caseType = 'register';
                        break;
                    case '2':
                        $caseType = 'withdrawl';
                        break;
                    case '1':
                        $caseType = 'voting';
                        break;
                    case '4':
                        $caseType = 'contact';
                        break;
                    case '5':
                        $caseType = 'loan';
                        break;
                    case '6':
                        $caseType = 'loanRepayment';
                        break;
                    case '7':
                        $caseType = 'checkbalance';
                        break;
                    case '8':
                        $caseType = 'susu_savings';
                        break;
                    case '9':
                        $caseType = 'addPackage';
                        break;
                    case '10':
                        $caseType = 'makepayment';
                        break;
            }
            } else {
                $lastsessionData = Session::where('session_id', $request->SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                $caseType = $lastsessionData->casetype;
            }
        }
        $newSessionData = Session::create([
            'request_json' => json_encode($request->all()),
            'mobile' => $request->Mobile,
            'session_id' => $request->SessionId,
            'sequence' => $request->Sequence,
            'message' => $request->Message,
            'casetype' => $caseType,
            'operator' => $request->Operator
        ]);

        $responseTypeData = $this->handleType($request->Type,$request->Message,$request->Sequence,$request->SessionId,$caseType,$request->ServiceCode, $company_id);
        if ($caseType == "register" && isset(($responseTypeData['internal_number'])) && !empty($responseTypeData['internal_number'])) {
            Session::where('id',$newSessionData->id)->update(['internal_number'=> $responseTypeData['internal_number']]);
        }
        $lastSelectedData = Session::where('session_id', $request->SessionId)
                          ->whereNotNull('selected_plan_id')
                          ->whereNotNull('payment_system')
                          ->first();

        $selected_plan_id = $lastSelectedData ? $lastSelectedData->selected_plan_id : null; 
        $payment_system = $lastSelectedData ? $lastSelectedData->payment_system : null; 

        if($payment_system != "" && $selected_plan_id != ""){

            $planPrice = Plan::where('plan_id', $selected_plan_id)->first();
                if($payment_system == 1){
                    $column = "daily";
                } else if($payment_system == 2){
                    $column = "weekly";
                } else {
                    $column = "monthly";
                }
            $priceValue = $planPrice->$column;
            $plan_name = $planPrice->name;
            // return $this->responseBuilderForPayment(
            //     $request->SessionId, 
            //     'AddToCart',                         
            //     $responseTypeData['message'], 
            //     $plan_name,
            //     1,
            //     $priceValue,
            //     $responseTypeData['label'],                      
            //     $responseTypeData['data_type'],                             
            //     'text'                               
            // );
            return $this->responseBuilder(
                $request->SessionId, 
                'response',                         
                $responseTypeData['message'], 
                $responseTypeData['label'],                      
                $request->ClientState ? $request->ClientState:"",                               
                $responseTypeData['data_type'],                             
                'text'                               
            );
        } else {
            return $this->responseBuilder(
                $request->SessionId, 
                'response',                         
                $responseTypeData['message'], 
                $responseTypeData['label'],                      
                $request->ClientState ? $request->ClientState:"",                               
                $responseTypeData['data_type'],                             
                'text'                               
            );
        }
    }

    public function hubtelUSSDCallback(Request $request, $company_id)
    {
        CallbackRequest::create(['request' => json_encode($request->all())]);
        $lastTransaction = Transaction::where('recurring_invoice_id',$request->Data['RecurringInvoiceId'])->orderBy('created_at','DESC')->first();
        if ($request->Message=="Success" && $request->ResponseCode == "0000") {
            $plan_id = $lastTransaction->selected_plan_id;
            $phone_number = $lastTransaction->phone_number;
            if ($lastTransaction->status != "pending" && !empty($lastTransaction->cancel_plan_id)) {
               $cancelOldRecurring = Transaction::where('phone_number',$phone_number)->where('selected_plan_id',$lastTransaction->cancel_plan_id)->whereNotNull('recurring_invoice_id')->first();
               if (!empty($cancelOldRecurring) && !empty($cancelOldRecurring->recurring_invoice_id)) {
                $token = base64_encode("lRk35Zg:221d0bb469cb4a9da90c198190db640a");
                $response = Http::withHeaders([
                    'Authorization' => "Basic {$token}",
                    'Content-Type' => 'application/json'
                ])->delete("https://rip.hubtel.com/api/proxy/2023714/cancel-invoice/{$cancelOldRecurring->recurring_invoice_id}", [
                    "callbackUrl" => "https://smido.vikartrtechnologies.com/api/$company_id/ussd/callback"
                ]);
                $responseDataCancel = $response->getBody()->getContents();
                Log::info("Plan cancelled request{$responseDataCancel}");
               }
               if (!empty($cancelOldRecurring)) {
                Subscription::where('phone_number',$phone_number)->where('plan_id',$lastTransaction->cancel_plan_id)->update([
                    'status' => "cancelled"
                ]);
               }
            }
            if (strpos($phone_number, '233') === 0) {
                $phone_number = '0' . substr($phone_number, 3);
            }
            Transaction::where('recurring_invoice_id',$request->Data['RecurringInvoiceId'])->where('status','authenticated')->delete();
            Transaction::create(['name'=>$request->Data['Description'],'recurring_invoice_id'=>$request->Data['RecurringInvoiceId'],'amount'=>$request->Data['RecurringAmount'],'selected_plan_id'=>$plan_id,'status'=>'success','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now()]);
            Customer::where('phone_number',$phone_number)->update([
                // 'packages_start_index' => 0,
                'plan_id' => $plan_id
            ]);
        }

    }

    public function handleType($type,$inputmessage,$sequence,$SessionId,$caseType,$ServiceCode, $company_id){
        $message = "";
        $label ="";
        $dataType = "";

        $sessionData = Session::where('session_id', $SessionId)
                    ->where('sequence', 2)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->first();
        
        $company = Company::where('company_id', $company_id)->first();

        switch ($type) {
            case 'Initiation':
                $message = "Welcome to ".$company->name.".\nWhat do you want to do:\n1. Vote for your favorite character.\n2. Withdrawl.";
                $label = "Welcome";
                $dataType = "input";
                break;
            case 'Response':
                switch ($caseType) {
                    case 'register':
                        $RegisterScreen = $this->handleRegisterScreen($SessionId,$sequence,$company_id);
                        $message = $RegisterScreen['message'];
                        $label = $RegisterScreen['label'];
                        $dataType = $RegisterScreen['data_type'];
                        $internal_number = !empty($RegisterScreen['internal_number']) ? $RegisterScreen['internal_number']:0;
                        break;
                    case 'checkbalance':
                        $checkBalanceScreen = $this->handleCheckBalanceScreen($SessionId,$sequence,$company_id);
                        $message = $checkBalanceScreen['message'];
                        $label = $checkBalanceScreen['label'];
                        $dataType = $checkBalanceScreen['data_type'];
                        break;
                    case 'makepayment':
                        $makePaymentScreen = $this->handleMakePaymentScreen($SessionId,$sequence,$company_id);
                        $message = $makePaymentScreen['message'];
                        $label = $makePaymentScreen['label'];
                        $dataType = $makePaymentScreen['data_type'];
                        break;
                    case 'contact':
                        $ContactScreen = $this->handleContactScreen($SessionId,$sequence,$company_id);
                        $message = $ContactScreen['message'];
                        $label = $ContactScreen['label'];
                        $dataType = $ContactScreen['data_type'];
                        break;
                    case 'loan':
                        $LoanScreen = $this->handleLoanRequestScreen($SessionId,$sequence,$company_id);
                        $message = $LoanScreen['message'];
                        $label = $LoanScreen['label'];
                        $dataType = $LoanScreen['data_type'];
                        break;
                    case 'loanRepayment':
                        $LoanRepaymentScreen = $this->handleLoanRepaymentScreen($SessionId,$sequence,$company_id);
                        $message = $LoanRepaymentScreen['message'];
                        $label = $LoanRepaymentScreen['label'];
                        $dataType = $LoanRepaymentScreen['data_type'];
                        break;
                    case 'withdrawl':
                        $WithdrawlScreen = $this->handleWithdrawlScreen($SessionId,$sequence,$company_id);
                        $message = $WithdrawlScreen['message'];
                        $label = $WithdrawlScreen['label'];
                        $dataType = $WithdrawlScreen['data_type'];
                        break;
                    case 'susu_savings':
                        $SusuSavingsScreen = $this->handleSusuSavingsScreen($SessionId,$sequence,$company_id);
                        $message = $SusuSavingsScreen['message'];
                        $label = $SusuSavingsScreen['label'];
                        $dataType = $SusuSavingsScreen['data_type'];
                        break;
                    case 'addPackage':
                        $AddPackageScreen = $this->handleAddPackageScreen($SessionId,$sequence,$company_id);
                        $message = $AddPackageScreen['message'];
                        $label = $AddPackageScreen['label'];
                        $dataType = $AddPackageScreen['data_type'];
                        break;
                    case 'voting':
                        $VotingScreen = $this->handleVotingScreen($SessionId,$sequence,$company_id);
                        $message = $VotingScreen['message'];
                        $label = $VotingScreen['label'];
                        $dataType = $VotingScreen['data_type'];
                        break;
                }
                break;       
            
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number" => !empty($internal_number) ? $internal_number:60
        ];
    }

    /**
     * Build a structured response.
     *
     * @param string $sessionId
     * @param string $type
     * @param string $message
     * @param string $label
     * @param string $clientState
     * @param string $dataType
     * @param string $fieldType
     * @return JsonResponse
     */
    public function responseBuilder(
        string $sessionId,
        string $type,
        string $message,
        string $label,
        string $clientState="",
        string $dataType,
        string $fieldType
    ): JsonResponse {
        // Structure the response array
        $response = [
            'SessionId'   => $sessionId,
            'Type'        => ($dataType == "display") ? "release":$type,
            'Message'     => $message,
            'Label'       => $label,
            'ClientState' => $clientState,
            'DataType'    => $dataType,
            'FieldType'   => ($dataType == "display") ? "":$fieldType,
        ];

        Session::create([
            'response_json' => json_encode($response),
            'session_id' => $sessionId,
            'message' => $message
        ]);


        // Return the response as a JSON
        return response()->json($response);
    }

    public function responseBuilderForPayment(
        string $sessionId,
        string $type,
        string $message,
        string $plan_name,
        string $quantity,
        string $priceValue,
        string $label,
        string $dataType,
        string $fieldType
    ): JsonResponse {
        // Structure the response array
        $response = [
            'SessionId'   => $sessionId,
            'Type'        => $type,
            'Message'     => $message,
            'Item' => [
                'ItemName' => $plan_name, 
                'Qty' => $quantity,                 
                'Price' => 0.001    // $priceValue replace this variable to 1 when project goes to live    
            ],
            'Label'       => $label,
            'DataType'    => $dataType,
            'FieldType'   => $fieldType,
        ];

        Session::create([
            'response_json' => json_encode($response),
            'session_id' => $sessionId,
            'message' => $message
        ]);


        // Return the response as a JSON
        return response()->json($response);
    }

    public function handleRegisterScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
        $internal_number=0;
        switch ($sequence) {
            case '2':
                $message = "Enter your First Name";
                $label = "FirstName";
                $dataType = "text";
                $internal_number = 2;
                break;
            case '3':
                $message = "Enter your Last Name";
                $label = "LastName";
                $dataType = "text";
                $internal_number = 3;
                break;
            case '4':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                $internal_number = 4;
                break;
            case '5':
                $message = "Enter Provider\n1. Vodafone\n2.MTN";
                $label = "Provider";
                $dataType = "text";
                $internal_number = 5;
                break;
            case '6':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                // ->whereNotNull('message')
                // ->whereNotNull('request_json')
                // ->whereNull('response_json')
                // ->orderBy('id', 'desc')
                // ->first();
                // $oldCustomerInfo = Customer::where('phone_number', $phoneNumber->message)->where('reset_pin',1)->first();
                // if (!empty($oldCustomerInfo)) {
                //     $message = "As you are requested to reset your account Please enter the otp which comes up in your phone number.";
                //     $label = "PIN";
                //     $dataType = "text";
                // }else{
                    $message = "Enter 4 digits pin";
                    $label = "PIN";
                    $dataType = "text";
                    $internal_number = 6;
                // }
                break;
            default:
            if ($sequence >= 7) {
                $internal_number=7;
                    $sessionData = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4) 
                                ->first();                    

                    $firstName = Session::where('session_id', $SessionId)->where('internal_number',3)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();
                    $lastName = Session::where('session_id', $SessionId)->where('internal_number',4)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->first();
                    $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();
                    $provider = Session::where('session_id', $SessionId)->where('internal_number',6)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc')
                                ->first();
                    $PIN = Session::where('session_id', $SessionId)->where('sequence',7)->where('casetype','register')
                                ->orderBy('id', 'desc') 
                                ->first();
                    $customerInfo = Customer::where('phone_number', $phoneNumber->message)->first();
                    $operator_value = $provider->message;
                    if($operator_value == "1"){
                        $Operator = "vodafone_gh_rec";
                    } else {
                        $Operator = "mtn_gh_rec";
                    }
                    if (empty($customerInfo) && $sequence ==7) {
                        $companyID_for_create = Company::where('company_id', $company_id)->first();
                        Customer::create([
                            'name' => $firstName->message . " " . $lastName->message,
                            'phone_number' => $phoneNumber->message,
                            'pin' => $PIN->message,
                            'operator_channel'=> $Operator,
                            'company_id' => $companyID_for_create->id
                        ]);
                    }else{
                        if ($sequence ==7) {
                            $message = "Customer already exists with this phone number!";
                            $label = "Customer";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }

                        
                    }


                    Session::where('session_id', $SessionId)->update([
                        // 'packages_start_index' => 0,
                        'package_selection' => true
                    ]);
                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('selected_plan_id')
                                  ->first();
                $selected_plan_id = $lastsessionData ? $lastsessionData->selected_plan_id : null; 
                if($selected_plan_id != '' && empty($otpVerifySubmit)){
                    // handle payment
                    $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNotNull('request_json')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
                    $payment_system = $lastsessionData->message;
                    $selectedPlanWithPaymentSystem = Plan::where('plan_id', $selected_plan_id)->first();
                    $plan_name = $selectedPlanWithPaymentSystem->name;
                    if($payment_system == 1){
                        $Pay_Role = "DAILY";
                        $pay_price = $selectedPlanWithPaymentSystem->daily;
                    } else if($payment_system == 2) {
                        $Pay_Role = "WEEKLY";
                        $pay_price = $selectedPlanWithPaymentSystem->weekly;
                    } else {
                        $Pay_Role = "MONTHLY";
                        $pay_price = $selectedPlanWithPaymentSystem->monthly;
                    }
                    $pay_price =floatval($pay_price);
                    Session::create([
                        'selected_plan_id' => $selected_plan_id,
                        'session_id' => $SessionId,
                        'payment_system' => $payment_system,
                    ]);
                    
                    Log::info("Payment Initiated sessionID:{$SessionId} and planID:{$selected_plan_id} with payment system:{$payment_system}");
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
                        "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                        "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                        "description" => $plan_name,
                        "startTime" => now()->addMinutes(5)->format('H:i'),
                        "paymentInterval" => $Pay_Role,
                        "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "paymentOption" => "MobileMoney",
                        "channel" => $Operator,
                        "customerName" => $firstName->message . " " . $lastName->message,
                        "recurringAmount" => $pay_price,
                        "totalAmount" => $pay_price,
                        "initialAmount" => $pay_price,
                        "currency" => "GHS",
                        "callbackUrl" => "https://smido.vikartrtechnologies.com/api/$company_id/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                    ]);

                    Transaction::create([
                        'name'=> $plan_name,
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'selected_plan_id'=>$selected_plan_id,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                        'status' => 'pending',
                        'amount' => $pay_price
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Enter OTP";
                    $label = "PaymentOTP";
                    $dataType = "text";
                    break;

                }
                if (!empty($otpVerifySubmit)) {
                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otpPrefix}-{$lastOTPsession->message}"
                    ]);
                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }else{
                    // $lastsessionData = Session::where('session_id', $SessionId)
                    // ->whereNotNull('recurring_invoice_id')
                    // ->whereNotNull('request_id')
                    // ->whereNotNull('otpPrefix')
                    // ->first();
                    // $selected_plan_id = $lastsessionData ? $lastsessionData->selected_plan_id : null; 

                    $session = Session::where('session_id', $SessionId)->orderBy('id', 'desc')->skip(2)->first();
                    $start = $session->packages_start_index ? $session->packages_start_index : 0;
                    
                    return $this->handlePackageNavigation($SessionId,$start,$company_id);
                }

            }
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number"=> $internal_number
        ];
    }

    public function hubtelUSSDtest(){
        $token = base64_encode("lRk35Zg:221d0bb469cb4a9da90c198190db640a"); 
        $response = Http::withHeaders([
            'Authorization' => "Basic {$token}",
            'Content-Type' => 'application/json'
        ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
            "orderDate" => now()->addDays(1)->format('Y-m-d\TH:i:s'),
            "invoiceEndDate" => now()->addDays(3)->format('Y-m-d\TH:i:s'),
            "description" => "Extreme Gaming Service",
            "startTime" => now()->format('H:i'),
            "paymentInterval" => "DAILY",
            "customerMobileNumber" => "233200777262",
            "paymentOption" => "MobileMoney",
            "channel" => "vodafone_gh_rec",
            "customerName" => "Bhavik Chudashama",
            "recurringAmount" => 0.01,
            "totalAmount" => 0.01,
            "initialAmount" => 0.01,
            "currency" => "GHS",
            "callbackUrl" => "https://smido.vikartrtechnologies.com/api/ussd/callback"
        ]);
        dd($response->getBody()->getContents());
    }

    public function handleCheckBalanceScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $balance = Customer::where('phone_number', $phoneNumberforBalance->message)->where('pin', $PINforBalance->message)->first();
                if (empty($balance)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                // if (!empty($balance->reset_pin) && $balance->reset_pin == 1 ) {
                //     $message = "Please do one time setup up from register to get your details!";
                //     $label = "PIN";
                //     $dataType = "display";
                //     return [
                //         "message" => $message,
                //         "label"=>$label,
                //         "data_type"=>$dataType
                //     ];
                // }
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? "{$balance->balance} GHS" : '0 GHS';
                $message = "Name: {$balance->name}\nPhone Number: {$balance->phone_number}\nBalance: ". $balance_amount;
                $label = "Balance";
                $dataType = "text";
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleWithdrawlScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your candidate code";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $balance = Candidate::where('phone_number', $phoneNumberforBalance->message)->where('candidate_code', $PINforBalance->message)->first();
                if (empty($balance)) {
                    $message = "Phone and Candidate Code doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? "{$balance->balance} GHS" : '0 GHS';
                $message = "Enter the amount you want to withdraw";
                $label = "Amount";
                $dataType = "text";
                break;
            case '5':
                $BalanceAmount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $phoneNumberforBalance = Session::where('session_id', $SessionId)
                ->whereNotNull('message')
                ->whereNotNull('request_json')
                ->whereNull('response_json')
                ->orderBy('id', 'desc') 
                ->skip(2) 
                ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                $balance = Candidate::where('phone_number', $phoneNumberforBalance->message)->where('candidate_code', $PINforBalance->message)->first();
                if($BalanceAmount->message > $balance){
                    $message = "Your wallet doesn't have that much balance";
                    $label = "Amount";
                    $dataType = "text";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Withdrawl Success";
                $label = "Amount";
                $dataType = "text";
                WithdrawlRequest::create([
                    'customer_name' => $balance->name,
                    'customer_phone_number' => $balance->phone_number,
                    'amount' => $BalanceAmount->message
                ]);
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleLoanRequestScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $phoneNumberforLoan->message)->where('pin', $PINforLoan->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($customer_details) && !empty($customer_details->balance)) ? "{$customer_details->balance} GHS" : '0 GHS';
                $loan_details = Loan::where('customer_id', $customer_details->id)->first();
                $max_amount = $customer_details->balance * $loan_details->factor;

                $days = $customer_details->created_at->diffInDays(Carbon::now());
                if($days >= $loan_details->lehibility_period){
                    if($loan_details->set_volume == 'fixed'){
                        $message = "Name: " . $customer_details->name . "\nBalance: " . $balance_amount . "\nLoan Balance: Enter the amount between". $loan_details->minimum_value . "to" . $loan_details->maximum_value;
                        $label = "Customer Details";
                        $dataType = "text";
                    } else {
                        $message = "Name: " . $customer_details->name . "\nBalance: " . $balance_amount . "\nMaximum amount you can take: ".$max_amount."\nLoan Balance: Enter the amount you want.";
                        $label = "Customer Details";
                        $dataType = "text";
                    }
                } else {
                    $message = "Hello " . $customer_details->name . "! Sorry you are not eligible for now. Do contribute some more and come back later.";
                    $label = "Customer Not Qualify";
                    $dataType = "text";
                }
                break;
            case '5';
                $AmountforLoan = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->first();
                
                $phoneNumberforLoan = Session::where('session_id', $SessionId)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->skip(2) 
                    ->first();

                $customer_details = Customer::where('phone_number', $phoneNumberforLoan->message)->first();
                $loan_details = Loan::where('customer_id', $customer_details->id)->first();
                $max_amount = $customer_details->balance * $loan_details->factor;

                if($loan_details->set_volume == 'fixed'){
                    if($AmountforLoan->message > $loan_details->maximum_value){
                        $message = "You can not take the loan more than " . $loan_details->maximum_value;
                        $label = "Loan Amount";
                        $dataType = "text";
                    } else {
                        $message = "Thank you for succesfully making a loan request. You will be contacted soon.";
                        $label = "Loan Amount";
                        $dataType = "text";

                        LoanRequest::create([
                            'customer_name' => $customer_details->name,
                            'customer_phone_number' => $customer_details->phone_number,
                            'amount' => $AmountforLoan->message
                        ]);
                    }
                } else {
                    if($AmountforLoan->message > $max_amount){
                        $message = "You can not take the loan more than " . $max_amount;
                        $label = "Loan Amount";
                        $dataType = "text";
                    } else {
                        $message = "Thank you for succesfully making a loan request. You will be contacted soon.";
                        $label = "Loan Amount";
                        $dataType = "text";

                        LoanRequest::create([
                            'customer_name' => $customer_details->name,
                            'customer_phone_number' => $customer_details->phone_number,
                            'amount' => $AmountforLoan->message
                        ]);
                    }
                }
                break;
            
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleLoanRepaymentScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $phoneNumberforLoan->message)->where('pin', $PINforLoan->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($customer_details) && !empty($customer_details->balance)) ? "{$customer_details->balance} GHS" : '0 GHS';
                $loan_details = Loan::where('customer_id', $customer_details->id)->first();

                $message = "Hello " . $customer_details->name . "\nWallet Balance: " . $balance_amount . "\nLoan Balance: " . $customer_details->loan_balance . "\n1.Make 1-time payment. \n2.Make recurring payment.";
                $label = "Customer Details";
                $dataType = "text";
                break;
            case '5':
                $paymentPlanforLoanRepayment = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                if($paymentPlanforLoanRepayment->message == '1'){
                    $message = "Enter the amount";
                    $label = "Loan Repayment";
                    $dataType = "text";
                } else {
                    $message = "Recurring Payment";
                    $label = "Loan Repayment";
                    $dataType = "text";
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleContactScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";

        $company = Company::where('company_id', $company_id)->first();
        $phone_number = $company->phone_number;
        $location = $company->location;
        $email = $company->email;

        switch ($sequence) {
            case '2':
                $message = "Phone number: " . $phone_number ."\nLocation: " . $location . "\nEmail: " . $email;
                $label = "Contact";
                $dataType = "display";
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleAddPackageScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
         Log::info("sequence:{$sequence}");
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $phoneNumberforUpdate = Session::where('session_id', $SessionId)
                ->whereNotNull('message')
                ->whereNotNull('request_json')
                ->whereNull('response_json')
                ->orderBy('id', 'desc')
                ->first();
                $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->first();
                if (!empty($customer)) {
                    $message = "Enter your Pin";
                    $label = "Pin";
                    $dataType = "text";
                }else{
                    $message = "No Customer record found with this number!";
                    $label = "No Customer record";
                    $dataType = "display";
                }
                break;
            case '4':
                Log::info("going here");
                $message = "Enter Provider\n1. Vodafone\n2.MTN";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            default:
            Log::info("going here");
                    $phoneNumberforUpdate = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',3)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc')
                                ->first();
                    $PINforupdate = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',4)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->first();
                    $operator = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',5)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->first();
                    $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->where('pin', $PINforupdate->message)->first();
                    if (empty($customer)) {
                        $message = "No Customer record found with this number!";
                        $label = "No Customer record";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    $operator_value = $operator->message;
                    if($operator_value == "1"){
                        $Operator = "vodafone_gh_rec";
                    } else {
                        $Operator = "mtn_gh_rec";
                    }
                    Customer::where('phone_number', $phoneNumberforUpdate->message)->update(['operator_channel'=> $Operator]);
                    // $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->where('pin', $PINforupdate->message)->first();
                    // if (!empty($customer->reset_pin) && $customer->reset_pin == 1 ) {
                    //     $message = "Please do one time setup up from register to get your details!";
                    //     $label = "PIN";
                    //     $dataType = "display";
                    //     return [
                    //         "message" => $message,
                    //         "label"=>$label,
                    //         "data_type"=>$dataType
                    //     ];
                    // }
                    $plan = Plan::where('plan_id', $customer->plan_id)->first();

                    $session = Session::where('session_id', $SessionId)->first();
                    $start = $session ? $session->packages_start_index : 0;
                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('selected_plan_id')
                                  ->first();
                $selected_plan_id = $lastsessionData ? $lastsessionData->selected_plan_id : null; 
                if($selected_plan_id != '' && empty($otpVerifySubmit)){
                    // handle payment
                    $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNotNull('request_json')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
                    $payment_system = $lastsessionData->message;
                    $selectedPlanWithPaymentSystem = Plan::where('plan_id', $selected_plan_id)->first();
                    $plan_name = $selectedPlanWithPaymentSystem->name;
                    if($payment_system == 1){
                        $Pay_Role = "DAILY";
                        $pay_price = $selectedPlanWithPaymentSystem->daily;
                    } else if($payment_system == 2) {
                        $Pay_Role = "WEEKLY";
                        $pay_price = $selectedPlanWithPaymentSystem->weekly;
                    } else {
                        $Pay_Role = "MONTHLY";
                        $pay_price = $selectedPlanWithPaymentSystem->monthly;
                    }
                    $pay_price =floatval($pay_price);
                    Session::create([
                        'selected_plan_id' => $selected_plan_id,
                        'session_id' => $SessionId,
                        'payment_system' => $payment_system,
                    ]);
                    
                    Log::info("Payment Initiated sessionID:{$SessionId} and planID:{$selected_plan_id} with payment system:{$payment_system}");
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
                        "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                        "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                        "description" => $plan_name,
                        "startTime" => now()->addMinutes(5)->format('H:i'),
                        "paymentInterval" => $Pay_Role,
                        "customerMobileNumber" => strpos($phoneNumberforUpdate->message, '0') === 0 ? intval('233' . substr($phoneNumberforUpdate->message, 1)) : intval($phoneNumberforUpdate->message),
                        "paymentOption" => "MobileMoney",
                        "channel" => $Operator,
                        "customerName" => $customer->name,
                        "recurringAmount" => $pay_price,
                        "totalAmount" => $pay_price,
                        "initialAmount" => $pay_price,
                        "currency" => "GHS",
                        "callbackUrl" => "https://smido.vikartrtechnologies.com/api/$company_id/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                    ]);

                    Transaction::create([
                        'name'=> $plan_name,
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumberforUpdate->message,
                        'selected_plan_id'=>$selected_plan_id,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                        'status' => 'pending',
                        'amount' => $pay_price
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Enter OTP";
                    $label = "PaymentOTP";
                    $dataType = "text";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                if (!empty($otpVerifySubmit)) {
                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otpPrefix}-{$lastOTPsession->message}"
                    ]);
                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }else{
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }else{
                        return $this->handlePackageNavigationForAddNewPackage($SessionId,$start,$plan,$sequence);
                    
                }
                    
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleVotingScreen($SessionId,$sequence,$company_id){
        $message = "";
        $label ="";
        $dataType = "";
        $internal_number=0;
        switch ($sequence) {
            case '2':
                $message = "Enter your candidate code";
                $label = "CandidateCode";
                $dataType = "text";
                $internal_number = 2;
                break;
            case '3':

                $CandidateCode = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                $Candidate = Candidate::where('candidate_code', $CandidateCode->message)->first();

                if(isset($Candidate) && !empty($Candidate)){
                    $message = "Candidate Name : " . $Candidate->first_name . " " . $Candidate->last_name . "\nEnter the number of vote.";
                    $label = "CandidateName";
                    $dataType = "text";
                    $internal_number = 3;
                } else {
                    $message = "Wrong Candidate Code";
                    $label = "CandidateName";
                    $dataType = "text";
                    $internal_number = 3;
                }
                break;
            case '4':
                $numberOfVote = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $CandidateCode = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                $Candidate = Candidate::where('candidate_code', $CandidateCode->message)->first();
                $event = Event::where('id', $Candidate->event)->first();
                $amount = $event->amount_per_vote * $numberOfVote->message;
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if(empty($otpVerifySubmit)){
                    Log::info("Payment Initiated sessionID:{$SessionId} and amount:{$amount}");
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
                        "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                        "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                        "description" => 'VotingPayment',
                        "startTime" => now()->addMinutes(5)->format('H:i'),
                        "paymentInterval" => 'OneTime',
                        "customerMobileNumber" => strpos($Candidate->phone_number, '0') === 0 ? intval('233' . substr($Candidate->phone_number, 1)) : intval($Candidate->phone_number),
                        "paymentOption" => "MobileMoney",
                        "channel" => 'vodafone_gh_rec',
                        "customerName" => $Candidate->first_name . " " . $Candidate->last_name,
                        "recurringAmount" => $amount,
                        "totalAmount" => $amount,
                        "initialAmount" => $amount,
                        "currency" => "GHS",
                        "callbackUrl" => "https://smido.vikartrtechnologies.com/api/$company_id/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                    ]);

                    Transaction::create([
                        'name'=> $Candidate->first_name . " " . $Candidate->last_name,
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'phone_number'=> $Candidate->phone_number,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                        'status' => 'pending',
                        'amount' => $amount,
                        'company_id' => $company_id
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Enter OTP";
                    $label = "PaymentOTP";
                    $dataType = "text";

                } else {
                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otpPrefix}-{$lastOTPsession->message}"
                    ]);
                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                break;

        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number"=> $internal_number
        ];
    }

    public function handlePackageNavigation($SessionId,$start,$company_id) {

        $perPage = setting('admin.plans_per_page') ?? 6;
        
        $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
        $userInput = $lastsessionData->message;

        if ($userInput !== '#' && $userInput !== '0') {
            $planDetails = Plan::where('plan_id', $userInput)->first();
    
            if ($planDetails) {

                Session::create([
                    'selected_plan_id' => $planDetails->plan_id,
                    'session_id' => $SessionId
                ]);
                
                return [
                    "message" => $planDetails->name 
                                . "\nPrice: " . $planDetails->price 
                                . "\n1. Daily: " . $planDetails->daily 
                                . "\n2. Weekly: " . $planDetails->weekly 
                                . "\n3. Monthly: " . $planDetails->monthly,
                    "label" => "PaymentType",
                    "data_type" => "text"
                ];
            }
        }
        if ($userInput == '#') {
            $start += $perPage; 
        } elseif ($userInput == '0') {
            $start = max(0, $start - $perPage); 
        }
        // dd($start);

        Session::where('session_id', $SessionId)
                ->orderBy('id', 'desc')
                ->limit(2)
                ->update(['packages_start_index' => $start]);
        
        $company = Company::where('company_id', $company_id)->first();
        $companyId = $company->id;
        $plans = Plan::where('company_id', $companyId)
                     ->orderByRaw('CAST(plan_sequence AS UNSIGNED) ASC')
                     ->skip($start)
                     ->take($perPage)
                     ->get();
    
        $packages = "Choose your plan:";
        foreach ($plans as $plan) {
            $packages .= "\n" . $plan->plan_id . ". " . $plan->name;
        }
    
        $totalPlans = Plan::count();
        if ($start > 0) {
            $packages .= "\n0. Show me previous packages";
        }
        if ($start + $perPage < $totalPlans) {
            $packages .= "\n#. Show me next packages";
        }

        return [
            "message" => $packages,
            "label" => "Packages",
            "data_type" => "text"
        ];
    }

    public function handlePackageNavigationForAddNewPackage($SessionId,$start,$plan,$sequence) {
        $perPage = setting('admin.plans_per_page') ?? 6;
        
        $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
        $userInput = $lastsessionData->message;

        if ($userInput !== '#' && $userInput !== '0' && $sequence != "5") {
            $planDetails = Plan::where('plan_id', $userInput)->first();
    
            if ($planDetails) {

                Session::create([
                    'selected_plan_id' => $planDetails->plan_id,
                    'session_id' => $SessionId,
                ]);
                
                return [
                    "message" => $planDetails->name 
                                . "\nPrice: " . $planDetails->price 
                                . "\n1. Daily: " . $planDetails->daily 
                                . "\n2. Weekly: " . $planDetails->weekly 
                                . "\n3. Monthly: " . $planDetails->monthly,
                    "label" => "PaymentType",
                    "data_type" => "text"
                ];
            }
        }
        
        if ($userInput == '#') {
            $start += $perPage;  
        } elseif ($userInput == '0') {
            $start = max(0, $start - $perPage);  
        }

        Session::where('session_id', $SessionId)->update(['packages_start_index' => $start]);
        
        $company = Company::where('company_id', $company_id)->first();
        $companyId = $company->id;
        $plans = Plan::where('company_id', $companyId)
                     ->orderByRaw('CAST(plan_sequence AS UNSIGNED) ASC')
                     ->skip($start)
                     ->take($perPage)
                     ->get();
        if(isset($plan->name) && !empty($plan->name)){
            $packages = "Choose your new plan:";
        } else {
            $packages = "Choose your new plan:";
        }
        foreach ($plans as $plan) {
            $packages .= "\n" . $plan->plan_id . ". " . $plan->name;
        }
    
        $totalPlans = Plan::count();
        if ($start > 0) {
            $packages .= "\n0. Show me previous packages";
        }
        if ($start + $perPage < $totalPlans) {
            $packages .= "\n#. Show me next packages";
        }

        return [
            "message" => $packages,
            "label" => "Packages",
            "data_type" => "text"
        ];
    }
    
}

