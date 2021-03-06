<?php

use App\Mail\JoinUglySweater;
use App\Mail\ugly_sweater_submission_reminder;
use App\Mail\ugly_sweater_vote_now;
use App\Models\employee;
use App\Models\legacy_user;
use App\Models\log;
use App\Models\receivedsms;
use App\Models\sms;
use App\Models\submission;
use App\Models\submission_has_vote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use jbirch8865\AzureAuth\Http\Middleware\AzureAuth;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|

 
*/

Route::group([], function () {
    Route::get('contacts', function () {
        request()->validate(['name' => 'required|string|max:35']);

        $contacts = App\Models\Contact::with('belongs_to_company.customer')->where(['Active_Status' => 1, 'person_type' => 2])->where(function ($query) {
            $first_name = substr(request()->input('name'), 0, strpos(request()->input('name'), ' ') === false ? strlen(request()->input('name')) : strpos(request()->input('name'), ' '));
            $last_name = substr(request()->input('name'), strpos(request()->input('name'), ' ') === false ? strlen(request()->input('name')) + 1 : strpos(request()->input('name'), ' ') + 1);

            if ($last_name) {
                $query->where('first_name', 'LIKE', $first_name . "%")->where('last_name', 'LIKE', $last_name . '%');
            } else {
                $query->where('first_name', 'LIKE', $first_name . "%")->orWhere('last_name', 'LIKE', $first_name . '%');
            }
        })->paginate();
        return response()->json(["message" => "contacts", "contacts" => $contacts]);
    });
    Route::get('employees', function () {
        $employees = employee::with(['has_skills.skill', 'has_sent_sms' => function ($query) {
            $query->unread();
        }])->where(['Active_Status' => 1, 'person_type' => 1])->orderBy('first_name', 'asc')->get();
        return response()->json(["message" => "employees", "employees" => $employees]);
    });
    Route::get('employees/{employee}/sms', function (employee $employee) {
        request()->validate(['from' => 'required|string|max:15|in:bulk,job,scheduling']);
        $employee->load(['has_sent_sms.has_log.belongs_to_user', 'has_sent_sms' => function ($query) {
            $query->where(['to_number' => (request()->input('from') === 'bulk' ? env('twilio_bulk_number', "null") : (request()->input('from') === 'job' ? env('twilio_job_details_number', "null") : env('twilio_scheduling_number', "null")))])->orderBy('timestamp', 'desc')->paginate(15);
        }, 'has_received_sms.has_log.belongs_to_user', 'has_received_sms' => function ($query) {
            $query->where(['from_number' => (request()->input('from') === 'bulk' ? env('twilio_bulk_number', "null") : (request()->input('from') === 'job' ? env('twilio_job_details_number', "null") : env('twilio_scheduling_number', "null")))])->orderBy('timestamp', 'desc')->paginate(15);
        }]);
        return response()->json(["message" => "employee sms", "sms" => $employee]);
    });
    Route::post('employees/{employee}/sms', function (employee $employee) {
        $status = ['accepted' => 0, 'queued' => 1, 'sending' => 2, 'sent' => 3, 'receiving' => 4, 'received' => 5, 'delivered' => 6, 'undelivered' => 7, 'failed' => 8];
        request()->validate([
            'from' => 'required|string|max:15|in:bulk,job,scheduling',
            'message' => 'required|string|max:1600'
        ]);
        $account_sid = getenv("TWILIO_SID");
        $auth_token = getenv("TWILIO_AUTH_TOKEN");
        $twilio_number = (request()->input('from') === 'bulk' ? env('twilio_bulk_number', "null") : (request()->input('from') === 'job' ? env('twilio_job_details_number', "null") : env('twilio_scheduling_number', "null")));
        $log = new log;
        $log->log_entry = "sent sms";
        $log->log_type = 10;
        $log->person_id = legacy_user::user()->firstOrFail()->person_id;
        $log->save();
        $client = new Client($account_sid, $auth_token);
        $send_error = false;
        try {
            $message_instance = $client->messages->create(
                $employee->phone_number,
                ['from' => $twilio_number, 'body' => request()->input('message'), 'statusCallback' => env('SPECIAL_ENDPOINT_URL') . '/api/twilio_status_callback']
            );
        } catch (TwilioException $e) {
            $send_error = $e->getMessage();
        }
        $sms = new sms;
        $sms->twilio_sid = $send_error === false ? $message_instance->sid : uniqid("failed_");
        $sms->timestamp = date('Y-m-d H:i');
        $sms->from_number = $twilio_number;
        $sms->to_number = $employee->phone_number;
        $sms->message_received = 9;
        $sms->message_body = request()->input('message');
        $sms->log_id = $log->log_id;
        $sms->dh_read = 1;
        $sms->save();
        if ($send_error === false) {
            return response()->json(["message" => "sent sms", "sms" => $message_instance]);
        } else {
            return response()->json(["message" => "error sending sms", "error" => $send_error]);
        }
    });
    Route::get('employees/sms', function () {
        request()->validate([
            'from' => 'required|string|max:15|in:bulk,job,scheduling',
            'people' => 'required|array',
            'people.*' => 'integer',
            'pagination' => 'integer'
        ]);
        $from = request()->input('from') === "bulk" ? env('twilio_bulk_number') : (request()->input('from') === "scheduling" ? env('twilio_scheduling_number') : env('twilio_job_details_number'));
        $sms_received = DB::table('SMS_Log')->select(DB::raw('Users.username,SMS_Log.*,if(received.first_name IS null,false,true) as received,if(received.first_name IS null,CONCAT(sent.first_name," ",sent.last_name),CONCAT(received.first_name," ",received.last_name)) as person_name'))
            ->join('Log', 'SMS_Log.log_id', '=', 'Log.log_id')
            ->join('Users', 'Users.person_id', '=', 'Log.person_id')
            ->leftJoin('People as received', 'SMS_Log.to_number', '=', 'received.phone_number')
            ->leftJoin('People as sent', 'SMS_Log.from_number', '=', 'sent.phone_number')
            ->whereIntegerInRaw('received.person_id', request()->input('people'))
            ->where('SMS_Log.from_number', '=', $from)
            ->orderByDesc('timestamp')->simplePaginate(request()->input('paginate', 50));
        $sms_sent = DB::table('SMS_Log')->select(DB::raw('Users.username,SMS_Log.*,if(received.first_name IS null,false,true) as received,if(received.first_name IS null,CONCAT(sent.first_name," ",sent.last_name),CONCAT(received.first_name," ",received.last_name)) as person_name'))
            ->leftjoin('Log', 'SMS_Log.log_id', '=', 'Log.log_id')
            ->leftjoin('Users', 'Users.person_id', '=', 'Log.person_id')
            ->leftJoin('People as received', 'SMS_Log.to_number', '=', 'received.phone_number')
            ->leftJoin('People as sent', 'SMS_Log.from_number', '=', 'sent.phone_number')
            ->whereIntegerInRaw('sent.person_id', request()->input('people'))
            ->where('SMS_Log.to_number', '=', $from)
            ->orderByDesc('timestamp')->simplePaginate(request()->input('paginate', 50));
        return response()->json(["message" => "employee sms", "sent" => $sms_sent, "received" => $sms_received]);
    });
    Route::put('sms', function () {
        request()->validate([
            'sid' => 'required|string|max:40',
            'dh_read' => 'required|bool'
        ]);
        DB::table('SMS_Log')->where('twilio_sid', '=', request()->input('sid'))->update(['dh_read' => request()->input('dh_read')]);
        return response()->json(["message" => "sms updated"]);
    });

    Route::post('ugly_sweater_vote_now', function() {
        request()->validate(['email' => 'email|required_without:all','all' => "boolean|required_without:email"]);
        Mail::to(request()->input('email'))->send(new ugly_sweater_vote_now);
        return response()->json(['message'=>'email sent'],201);
    });

    Route::post('ugly_sweater_submission', function() {
        request()->validate([
            'imageUrl' => 'string|max:1000000|required',
            'comment' => 'string|max:255|required'
        ]);
        $azure = new AzureAuth;
        $submission = new submission;
        $submission->user_id = legacy_user::user()->firstOrFail()->access_token;
        $submission->comment = request()->input('comment');
        $submission->url = request()->input('imageUrl');
        $submission->save();
        return response()->json(['message' => 'Submission Received','submission' => $submission],201);
    });
    Route::put('ugly_sweater_submission/{submission}', function(submission $submission) {
        request()->validate([
            'imageUrl' => 'string|max:1000000|required',
            'comment' => 'string|max:255|required'
        ]);
        $submission->comment = request()->input('comment');
        $submission->url = request()->input('imageUrl');
        $submission->save();
        return response()->json(['message' => 'Submission Updated','submission' => $submission],201);
    });
    Route::get('ugly_sweater_submission', function() {
        $submission = submission::with('user')->get();
        return response()->json(['message' => 'submissions','submissions' => $submission]);
    });    
    Route::get('ugly_sweater_who_I_voted_for', function() {
        $azure = new AzureAuth();
        $votes = submission_has_vote::where('user_id',$azure->Get_User_Oid(Request()))->get();
        return response()->json(['message' => 'my votes','myVotes' => $votes]);
    });    
    Route::post('ugly_sweater_I_voted', function() {
        request()->validate(['id' => 'required|exists:submissions']);
        $azure = new AzureAuth();
        $vote = new submission_has_vote;
        $vote->user_id = $azure->Get_User_Oid(Request());
        $vote->submission_id = request()->input('id');
        $vote->save();
        return response()->json(['message' => 'Thank you for your vote','myVote' => $vote],201);
    });    

    Route::delete('ugly_sweater_I_voted/{submission_has_vote}', function(submission_has_vote $submission_has_vote) {
        $submission_has_vote->forceDelete();
        return response()->json(['message' => 'Your vote was removed','Canceled' => $submission_has_vote],201);
    });     

    Route::get('quick_query', function () {
        return new JoinUglySweater;
    });
});
