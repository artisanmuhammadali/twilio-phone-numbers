<?php

use App\Library\VoiceRSS;
use App\Models\NumberVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return 'welcome';
});
Route::get('/verify-number/{phone_number}', function () {
    $number = '+'.request()->phone_number;
    
    $sid = env('TWILIO_ACC_ID');
    $token = env('TWILIO_AUTH_TOKEN');
    $twilio = new Client($sid, $token);
    $validation_request = $twilio->validationRequests->create(
        $number,
        [
            "friendlyName" => "Third Party VOIP Number",
            "statusCallback" => env('TWILIO_CALLBACK_URL'),
        ]
    );
    $data = $validation_request->toArray();
    
    $formattedString = implode(' ', str_split($data['validationCode']));
    $speechFilelink = Str::toAudio($formattedString);
    $voice = str_replace('/var/www/twilio-phone-numbers/public/' , 'https://voice.truckverse.net/' ,$speechFilelink);
    NumberVerification::create([
        'number'=> $number,
        'code'=>$data['validationCode'],
        'callSid'=>$data['callSid'],
        'voice'=>$voice
    ]);
    return $voice;
});


Route::any('/listen-to-twilio-verification-call' , function(Request $request){
    // 14157234000
    Log::info('listen-to-twilio-verification-call');
    $from = $request['data']['payload']['from'];
    $event = $request['data']['event_type'];
    Log::info($request);
    Log::info($event);
    if($event == 'call.initiated')
    {
        $callControlId = $request['data']['payload']['call-control-id'];
        $token = env('TELNYX_API_KEY');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => "Bearer $token",
        ])->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/answer", [
            "client_state" => null,
            "command_id" => "891510ac-f3e4-11e8-af5b-de00688a4901",
            "webhook_url" => "https://voice.truckverse.net/telnyx-webhook",
            "webhook_url_method" => "POST",
            "send_silence_when_idle" => true
        ]);
    }
    if($from == '+14157234000' && $event == 'call.answered'){
        Log::info('call answered');
        $to = $request['data']['payload']['to'];
        $verification = NumberVerification::where('number', $to)->latest()->first();
        Log::info('get otp from db');
        if($verification){
            $response = new VoiceResponse();
            $response->play($verification->voice);
            Log::info($response);
            return $response;
        }
    }
    
    return true;
});

Route::any('/telnyx-webhook' , function(Request $request){
    // 14157234000
    Log::info('telnyx-webhook');
    Log::info($request);
    return true;
});
Route::any('/listen-to-twilio-verification-call-progress' , function(Request $request){
    // 14157234000
    Log::info('listen-to-twilio-verification-call-progress');
    Log::info($request->ip());
    return response('<?xml version="1.0" encoding="UTF-8"?>
    <Response>
    <Answer>
        <Say voice="alice">Hi</Say>
    </Answer>
    </Response>', 200)->header('Content-Type', 'text/xml');
});


Route::any('/receive-verification-callback' , function(Request $request){
    Log::info('receive-verification-callback');
    NumberVerification::where('CallSid', $request->CallSid)->update(['response'=>json_encode($request->all()) , 'status'=>$request->VerificationStatus]);
    Log::info($request);
    return true;
});

Route::any('/get-response' , function(){
    $verification = NumberVerification::latest()->get();  
    return response()->json($verification);
});