<?php

use App\Models\NumberVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;

Route::get('/', function () {
    return 'welcome';
});
Route::get('/verify-number/{phone_number}', function () {
    $number = request()->phone_number;
    
    $sid = env('TWILIO_ACC_ID');
    $token = env('TWILIO_AUTH_TOKEN');
    $twilio = new Client($sid, $token);
    $validation_request = $twilio->validationRequests->create(
        "+".$number,
        [
            "friendlyName" => "Third Party VOIP Number",
            "statusCallback" => env('TWILIO_CALLBACK_URL'),
        ]
    );
    $data = $validation_request->toArray();
    NumberVerification::create([
        'number'=> $number,
        'code'=>$data['validationCode'],
        'callSid'=>$data['callSid']
    ]);
    return $data;
});


Route::any('/listen-to-twilio-verification-call' , function(Request $request){
    // 14157234000
    Log::info('listen-to-twilio-verification-call');
    Log::info($request);
    Log::info($request->ip());
    // $response = new VoiceResponse();
    // $response->say('12', ['loop' => 2]);
    // $response->play('12', ['digits' => '12']);
    // return $response;
    return true;
});

Route::any('/listen-to-twilio-verification-call-failed' , function(Request $request){
    // 14157234000
    Log::info('listen-to-twilio-verification-call-failed');
    Log::info($request->ip());
    return true;
});
Route::any('/listen-to-twilio-verification-call-progress' , function(Request $request){
    // 14157234000
    Log::info('listen-to-twilio-verification-call-progress');
    Log::info($request->ip());
    return true;
});


Route::any('/receive-verification-callback' , function(Request $request){
    Log::info('receive-verification-callback');
    NumberVerification::where('CallSid', $request->CallSid)->update(['response'=>json_encode($request->all()) , 'status'=>$request->VerificationStatus]);
    Log::info($request);
    return true;
});