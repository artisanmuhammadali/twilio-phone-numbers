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

function numberToWords($input) {
    $numbers = explode(' ', $input);
    $formatter = new NumberFormatter('en', NumberFormatter::SPELLOUT);
    
    $words = array_map(function($number) use ($formatter) {
        return $formatter->format($number);
    }, $numbers);

    return implode(' ', $words);
}

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
    
    $formattedString = implode('W', str_split($data['validationCode']));
    
    
    $output = numberToWords($data['validationCode']);
    $speechFilelink = Str::toAudio($output);
    $voice = str_replace('/var/www/twilio-phone-numbers/public/' , 'https://voice.truckverse.net/' ,$speechFilelink);
    NumberVerification::create([
        'number'=> $number,
        'code'=>$data['validationCode'],
        'callSid'=>$data['callSid'],
        'voice'=>$voice,
        'formated_code'=>$formattedString,
        'code_as_text'=>$output,
        'connection_id'=>'test'
    ]);
    return $voice;
});

Route::any('/listen-to-twilio-verification-call', function(Request $request) {
    Log::info('Incoming call webhook received');
    Log::info($request->all());


    $from = array_key_exists('from' ,$request['data']['payload']) ?  $request['data']['payload']['from'] : '';
    $event = $request['data']['event_type'];
    $token = env('TELNYX_API_KEY');
    $callControlId = $request['data']['payload']['call_control_id'];

    if($from == '+14157234000'){
        if ($event == 'call.initiated') {
            Log::info('Answering call...');
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => "Bearer $token",
            ])->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/answer");
    
            Log::info("Call Answered: " . $response->body());
        }
        // Speak OTP when call is answered
        if ($event == 'call.answered') {
            $to = $request['data']['payload']['to'];
            $verification = NumberVerification::where('number', $to)->latest()->first();
            Log::info('Fetching OTP from database');

            if ($verification) {
                

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer $token",
                ])->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/send_dtmf", [
                    "digits" => $verification->formated_code,
                ]);

                Log::info("Speaking OTP: " . $response->body());
                $verification->update(['connection_id' => $callControlId]);
            }
            else{
                Log::info('no verification found' , $verification , $to);
            }
        }

        return response()->json(['status' => 'success']);
    }
});


// Route::any('/listen-to-twilio-verification-call' , function(Request $request){
//     // 14157234000
//     Log::info('listen-to-twilio-verification-call');
//     $from = $request['data']['payload']['from'];
//     $event = $request['data']['event_type'];
//     Log::info($request);
//     Log::info($event);
//     // if($event == 'call.initiated')
//     // {
//     //     $callControlId = $request['data']['payload']['call_control_id'];
//     //     $token = env('TELNYX_API_KEY');

//     //     $response = Http::withHeaders([
//     //         'Content-Type' => 'application/json',
//     //         'Accept' => 'application/json',
//     //         'Authorization' => "Bearer $token",
//     //     ])->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/answer", [
//     //         "client_state" => 'aGF2ZSBhIG5pY2UgZGF5ID1d',
//     //         "command_id" => '891510ac-f3e4-11e8-af5b-de00688a4901',
//     //         "webhook_url" => "https://voice.truckverse.net/telnyx-webhook",
//     //         "webhook_url_method" => "POST",
//     //         "send_silence_when_idle" => true
//     //     ]);
//     //     Log::info($response);
//     // }
//     if($from == '+14157234000' && $event == 'call.answered'){
//         $to = $request['data']['payload']['to'];
//         $verification = NumberVerification::where('number', $to)->latest()->first();
//         Log::info('get otp from db');
//         if($verification){
//             //method 1
//             // $response = new VoiceResponse();
//             // $response->play($verification->voice);

//             //method 2
//             // $response = new VoiceResponse();
//             // $response->say($verification->code_as_text);

//             //method 3
//             $response = new VoiceResponse();
//             $response->play('', [ 'digits' => $verification->formated_code]);

//             Log::info($response);
//             return $response;
//         }
//     }
    
//     return true;
// });

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
<Response><Play digits="1 2 3 1 2 3"></Play></Response>', 200)->header('Content-Type', 'text/xml');
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