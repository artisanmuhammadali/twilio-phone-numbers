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
    $formattedString2 = implode(' ', str_split($data['validationCode']));
    
    
    $output = numberToWords($formattedString2);
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
        
        // Speak OTP when call is answered
            $to = $request['data']['payload']['to'];
            $verification = NumberVerification::where('number', $to)->orderBy('_id', 'desc')->first();

            Log::info('Fetching OTP from database');

            if ($verification && $event == 'call.answered') {
                

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer $token",
                ])->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/send_dtmf", [
                    "digits" => $verification->formated_code,
                ]);

                Log::info("Send DTMF: " . $response->body());


                // $response = Http::withHeaders([
                //     'Content-Type' => 'application/json',
                //     'Accept' => 'application/json',
                //     'Authorization' => "Bearer $token",
                // ])->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/speak", [
                //     "payload" => $verification->code_as_text,
                //     "voice" => "Polly.Joanna"
                // ]);
                // Log::info("Speaking OTP: " . $response->body());

                $verification->update(['connection_id' => $callControlId]);
            }
            else{
                Log::info('no verification found'. $to);
            }
        

        return response()->json(['status' => 'success']);
    }
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