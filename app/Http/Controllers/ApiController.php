<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Validator;
use \Mandrill;
use Carbon\Carbon;
use App\Models\AssignedTables;
use App\Models\Bookings;
use App\Models\Staff;
use App\Models\Notifications;
use App\Events\EventNotification;

/**
 * @SWG\Swagger(
 *     schemes={"http"},
 *     host="192.168.1.14:8000",
 *     basePath="/api",
 *     @SWG\Info(
 *         version="1.0.0",
 *         title="FCC API",
 *         description="This is a online API Documentation for FCC",
 *         @SWG\Contact(
 *             name="CJ"
 *         )
 *     )
 * )
 */

class ApiController extends Controller
{
    protected $messages, $statusCode, $rules, $host, $lang = 'en';

    public function __construct()
    {
        $this->messages=config('messages');
        $this->rules=config('rules');
        $this->statusCode=config('define');
        $this->host="http://$_SERVER[HTTP_HOST]/";
    }

    public function copyObject(&$newObj, $obj) {
        foreach ($obj as $key => $value) {
            $newObj[$key] = $obj[$key];
        }
    }
    public function validationFailResponse1($status, $errors) {
        $status_code = $this->statusCode[$status];
        $data_error = $this->messages[$this->lang]['validation_messages'][$errors];
        return response()->json([
            'return_code' => $status,
            'data' => $data_error
        ], $status_code);
    }   
    public function validationFailResponse($status, $errors) {
        $status_code = $this->statusCode[$status];
        return response()->json([
            'return_code' => $status,
            'data' => $errors
        ], $status_code);
    }

    public function failResponse($status, $errors) {
        $status_code = $this->statusCode[$status];
        $data_error = $this->messages[$this->lang][$errors];
        return response()->json([
            'return_code' => $status,
            'data' => $data_error,
        ], $status_code);
    }

    public function jsonResponse($status, $data, $message = '') {

        $status_code = $this->statusCode[$status];
        $jsonData = array('return_code' => $status);
        if (!empty($message)) {
            $message = $this->messages[$this->lang][$message];
            $jsonData['message'] = $message;
        }
        if ($data !== null)
            $jsonData['data'] = $data;

        return response()->json($jsonData, $status_code);
    }

    public function getLang($lang) {
        return ($lang) ? $lang : $this->lang;
    }

    public function setLang($lang) {
        if ($lang)
            $this->lang = $lang;
    }

    public function checkValidation($request, $rule) {
        $validator = Validator::make($request->all(), $this->rules[$rule], $this->messages[$this->lang]['validation_messages']);
        return $validator;
    }

    public function new_log($id, $activity, $info1, $info2, $logged_at) {
        // $log = new Log();
        // $log->user_id = $id;
        // $log->activity = $activity;
        // $log->info1 = $info1;
        // $log->info2 = $info2;
        // $log->logged_at = $logged_at;
        // $log->save();
    }
    protected function sendBookingMail($booking, $user, $template) {
        
        if(empty($user->email)) return;

        $mail_details = config('mail-templates')[$this->lang][$template];
        array_push($mail_details['variables'], array('name' => 'id', 'content' => $booking->id));
        array_push($mail_details['variables'], array('name' => 'notes', 'content' => $booking->notes_by_guest));
        if($this->lang == 'cn') {
            array_push($mail_details['variables'], array('name' => 'date', 'content' => Carbon::parse($booking->date)->format('n月j日, Y')));
            array_push($mail_details['variables'], array('name' => 'time_slot', 'content' => str_replace('PM', '下午', str_replace('AM', '上午', Carbon::parse($booking->time)->format('g:i A')))));
        }
        else {
            array_push($mail_details['variables'], array('name' => 'date', 'content' => Carbon::parse($booking->date)->toFormattedDateString()));
            array_push($mail_details['variables'], array('name' => 'time_slot', 'content' => Carbon::parse($booking->time)->format('h:i A')));
        }
        array_push($mail_details['variables'], array('name' => 'people', 'content' => ($booking->number_of_people . ' ' . $this->messages[$this->lang]['people'])));
        array_push($mail_details['variables'], array('name' => 'host', 'content' => $this->host));
        $this->sendMail($user, $template, $mail_details);
    }

    protected function sendAdminMail($booking, $user, $subject) {
        $mandrill = new Mandrill(config('services')['mandrill']['secret']);
        $settings = GeneralSettings::first();
        $result = $mandrill->messages->sendTemplate('admin', [], array(
            'subject' => $subject,
            'to' => array(
                array(
                    'email' => $settings->notification_email,
                    'name' => $settings->notification_email,
                    'type' => 'to'
                )
            ),
            'from_email' => 'tech@wodebox.com',
            'from_name' => 'fccchina',
            'merge' => true,
            'merge_language' => 'mailchimp',
            'global_merge_vars' => array(
                array(
                    'name'=> 'booking',
                    'content' => $booking->id
                ),
                array(
                    'name'=> 'name',
                    'content' => $user->last_name ? $user->first_name . ' ' . $user->last_name : $user->first_name
                ),
                array(
                    'name'=> 'email',
                    'content' => $user->email
                ),
                array(
                    'name'=> 'phone',
                    'content' => $user->phone
                ),
                array(
                    'name'=> 'people',
                    'content' => $booking->people
                ),
                array(
                    'name'=> 'time',
                    'content' => Carbon::parse($booking->date)->format('Y-m-d') . ' ' . $booking->time_slot
                ),
                array(
                    'name'=> 'notes',
                    'content' => $booking->notes
                )
            )
        ));
        return $result;
    }
    private function sendMail($user, $template, $mail_details) {
        $mandrill = new Mandrill(config('services')['mandrill']['secret']);
        $result = $mandrill->messages->sendTemplate($template, [], array(
            'subject' => $mail_details['subject'],
            'to' => array(
                array(
                    'email' => $user->email,
                    'name' => $user->first_name.' '.$user->last_name,
                    'type' => 'to'
                )
            ),
            'from_email' => 'tech@wodebox.com',
            'from_name' => 'fccchina',
            'merge' => true,
            'merge_language' => 'mailchimp',
            'global_merge_vars' => $mail_details['variables']
        ));
        return $result;
    }

    public function upComingBooking($table_id) {
        return Bookings::Join('assigned_tables', 'assigned_tables.booking_id', '=', 'bookings.id')
        ->where('assigned_tables.table_id', '=', $table_id)
        ->whereRaw('now()<ADDTIME(CONVERT(bookings.date, DATETIME), bookings.time)')
        ->orderByRaw('bookings.date, bookings.time asc')
        ->get();
    }

    // notification
    // 'BookingCreated','BookingChanged','BookingDeleted','GuestCreated','GuestChanged','GuestDeleted','StaffCreated','StaffChanged','StaffDeleted'
    public function sendNotification($type, $staff_id, $key_info1 = null, $key_info2 = null, $key_info3 = null, $key_info4 = null, $key_info5 = null) {
        
        $staffs = Staff::all(); 
        foreach ($staffs as $staff) {
            if ($staff->id != $staff_id) {
                $notification = new Notifications();
                $notification->type = $type;
                $notification->staff_id = $staff->id;   // received staff id
                $notification->key_info1 = $key_info1;
                $notification->key_info2 = $key_info2;
                $notification->key_info3 = $key_info3;
                $notification->key_info4 = $key_info4;
                $notification->key_info5 = $staff_id;   // sending staff id
                $notification->save();
            }
        }

        try {
            $event = array(
                'type'      => $type,
                'staff_id'  => $staff_id,
                'key_info1' => $key_info1,
                'key_info2' => $key_info2,
                'key_info3' => $key_info3,
                'key_info4' => $key_info4,
                'key_info5' => $key_info5,
            );
            event(new EventNotification( $event));
            return $this->jsonResponse('STATUS_SUCCESS', $event);
            
        } catch(\Exception $e) {
            return $this->failResponse('STATUS_NOT_FOUND', 'notification_not_found');
        }
    }

    public function getNotification($id, Request $request) {

        $this->setLang($request->lang);    
        
        $staff = Staff::find($id); 
        if ($staff) {      
            $notifications = Notifications::where('staff_id', '=', $id)
            ->where('is_read', '=', 0)->get();
            return $this->jsonResponse('STATUS_SUCCESS', $notifications);        
        } else {
            return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }
    }

    public function updateNotification($id, Request $request) {

        $staff = Staff::find($id); 
        if ($staff) {      
            $notifications = Notifications::where('staff_id', '=', $id)
            ->where('is_read', '=', 0)
            ->update(['is_read' => 1]);
            return $this->jsonResponse('STATUS_SUCCESS', $notifications);        
        } else {
            return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }

        // $id => notification->id
        // $this->setLang($request->lang);  
        // $notification = Notifications::find($id); 
        // if ($notification) {
        //     $notification->is_read = 1;
        //     $notification->save();
        //     return $this->jsonResponse('STATUS_SUCCESS', $notification);            
        // }
        // return $this->failResponse('STATUS_NOT_FOUND', 'notification_not_found');        
    }
}
