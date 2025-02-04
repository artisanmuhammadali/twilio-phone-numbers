<?php

use App\Library\VoiceRSS;
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
    NumberVerification::create([
        'number'=> $number,
        'code'=>$data['validationCode'],
        'callSid'=>$data['callSid']
    ]);

    $tts = new VoiceRSS;
    $voice = $tts->speech([
        'key' => env('VOICE_RSS_API_KEY'),
        'hl' =>  'en-gb:Alice',
        'src' => $data['validationCode'],
        'r' => '0',
        'c' => 'mp3',
        'f' => '44khz_16bit_stereo',
        'ssml' => 'false',
        'b64' => 'false'
    ]);

    return $voice;
});


Route::any('/listen-to-twilio-verification-call' , function(Request $request){
    // 14157234000
    Log::info('listen-to-twilio-verification-call');
    $from = $request['data']['payload']['from'];
    $event = $request['data']['event_type'];
    Log::info($from);
    Log::info($event);
    if($from == '+14157234000' && $event == 'call.answered'){
        Log::info('call answered');
        $to = $request['data']['payload']['to'];
        $verification = NumberVerification::where('number', $to)->latest()->first();
        Log::info('get otp from db');
        if($verification){
            $formattedString = implode(' ', str_split($verification->code));
            // $response = new VoiceResponse();
            // $response->say($formattedString);
            Log::info($formattedString);
            return response('<?xml version="1.0" encoding="UTF-8"?>
                            <Response>
                            <Answer>
                                <Say voice="alice">'.$formattedString.'</Say>
                            </Answer>
                            </Response>', 200);
            // return true;
        }
    }
    
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