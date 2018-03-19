<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Guest;
use App\Models\Bookings;

use App\Models\Sms;
use Illuminate\Support\Facades\Auth;

use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Log;

use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Support\Facades\Password;

use Twilio;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\GuestTags;

class GuestController extends ApiController
{
    function make_seed()
    {
        list($usec, $sec) = explode(' ', microtime());
        return $sec + $usec * 1000000;
    }
    protected function guard()
    {
        return Auth::guard('api_guest');
    }

    public function sendResetLinkEmail(Request $request)
    {
        // 
        $this->validate($request, ['email' => 'required|email']);
        
        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $response = $this->broker()->sendResetLink(
            $request->only('email')
        );
        
        if ($response == Password::RESET_LINK_SENT)
            return response()->json(['success' => 'true', 'status' => trans($response)]);
        else 
            return response()->json(['error' => 'false', 'status' => trans($response)]);
            
    }
    protected function broker()
    {
        return Password::broker('users');
    }
    /**
     * @SWG\Post(
     *     path="/app/sms",
     *     tags={"Guests"},
     *     summary="Send code by sms",
     *     description="",
     *     operationId="api.guest.sms",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          in="body",
     *          name="body",
     *          description="",
     *          required=true,
     *          @SWG\Schema(
     *              type="object",
     *             @SWG\Property(
     *                  property="phone",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              )
     *          )
     *     ),
     *     @SWG\Response(
     *         response=200,
     *         description="Sms is sent successfully"
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */
    public function send_code(Request $request) {

        $lang = $this->getLang($request->lang);
        $validator = $this->checkValidation($request,'sms_rules');
        if ($validator->fails()) {
            return $this->validationFailResponse('STATUS_LOGIC_ERROR', $validator->errors());
        }
        srand($this->make_seed());
        $code = rand(100000,999999);
        $sms = Sms::where(['phone_number' => $request->phone])
        ->where('created_at', '>', Carbon::now()->subMinutes(10));
        if ($sms->count()) {
            $sms = $sms->first();
            if ($sms->sms_count_limit === 3) {
                return $this->failResponse('STATUS_LOGIC_ERROR', 'login_sms_limit');
            } else {
                $sms->sms_count_limit += 1;
            }
        } else {
            $sms = new Sms();
            $sms->phone_number = $request->phone;
        }

        try {
            Twilio::message($request->phone, $this->messages[$lang]['sms_code'].$code);
        }
        catch(\Services_Twilio_RestException $e) {
            return $this->failResponse('STATUS_LOGIC_ERROR', 'incorrect_phone');
        }

        $sms->created_at = Carbon::now();
        $sms->sms_code = $code;
        $sms->save();        
        return $this->jsonResponse('STATUS_SUCCESS', 'sms_sent_success');        
    }

    private function create_guest(Request $request, $errors = [])
    {
        $validator = $this->checkValidation($request,'guest_rules');
        if ($validator->fails()) {
            return $this->validationFailResponse('STATUS_LOGIC_ERROR', $validator->errors());
        }
        $user = new Guest();
        $this->copyObject($user, $request->except('lang', 'token', 'password', 'code'));
        $user->save();
        $this->new_log($user->id, "Create Guest", $user->name, NULL, NULL);

        $user->token = JWTAuth::fromUser($user);
        // $user->token_expire_at = Carbon::now()->addHour();
        $user->save();
        // $response = array();
        // $response['id'] = $user->id;
        // $response['token'] = $user->token;
        return $this->jsonResponse('STATUS_SUCCESS', $user, 'user_create_success');
    }

    /**
     * @SWG\Post(
     *     path="/app/guests",
     *     tags={"Guests"},
     *     summary="Create a guest by sms",
     *     description="",
     *     operationId="api.guest.create",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          in="body",
     *          name="body",
     *          description="",
     *          required=true,
     *          @SWG\Schema(
     *              type="object",
     *              @SWG\Property(
     *                  property="name",
     *                  type="string",
     *                  default="vlad",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="email",
     *                  type="string",
     *                  default="my@email.com",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="phone",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *              @SWG\Property(
     *                  property="code",
     *                  type="string",
     *                  default="768543",
     *                  example="",
     *              )
     *          )
     *     ),
     *     @SWG\Response(
     *         response=200,
     *         description="Created a guest successfully"
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */
    public function create_user_by_sms(Request $request)
    {
        $code = $request->code;
        $sms = Sms::where(['phone_number' => $request->phone])
                    ->where('created_at', '>', Carbon::now()->subMinutes(10))
                    ->orderBy('created_at', 'desc')->first();

        if (isset($code)) {
            if (isset($sms)) {
                if ($code == $sms->sms_code) {
                    return $this->create_guest($request);
                } else {
                    return $this->failResponse('STATUS_LOGIC_ERROR', 'login_incorrect_code');
                }
            } else {
                return $this->failResponse('STATUS_LOGIC_ERROR', 'sms_code_expire_limit');
            }
        } else {
            return $this->failResponse('STATUS_LOGIC_ERROR', 'sms_code_required');
        }    
    }
    /**
     * @SWG\Post(
     *     path="/admin/guests",
     *     tags={"Admin"},
     *     summary="Create a guest",
     *     description="",
     *     operationId="api.admin.create",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          in="body",
     *          name="body",
     *          description="",
     *          required=true,
     *          @SWG\Schema(
     *              type="object",
     *              @SWG\Property(
     *                  property="name",
     *                  type="string",
     *                  default="vlad",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="email",
     *                  type="string",
     *                  default="my@email.com",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="phone",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *              @SWG\Property(
     *                  property="code",
     *                  type="string",
     *                  default="768543",
     *                  example="",
     *              )
     *          )
     *     ),
     *     @SWG\Response(
     *         response=200,
     *         description="Created a guest successfully"
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */
    public function createGuest(Request $request) {
        
        $this->setLang($request->lang);       

        if (!$request->headers->user->authorizeRoles('guest', 1)) {
            return $this->failResponse('STATUS_FORRBIDDEN', 'user_not_permission');
        }
         
        $validator = $this->checkValidation($request,'guest_rules');
        if ($validator->fails()) {
            return $this->validationFailResponse('STATUS_LOGIC_ERROR', $validator->errors());
        }
        $user = new Guest();
        $this->copyObject($user, $request->except('lang', 'token', 'password', 'code', 'tags'));
        $user->save();
        $this->new_log($user->id, "Create Guest", $user->name, NULL, NULL);

        $user->token = JWTAuth::fromUser($user);
        // $user->token_expire_at = Carbon::now()->addHour();
        $user->save();
        $user->tags = [];
        if (count($request->tags)) {
            foreach ($request->tags as $tag) {
                $t = new GuestTags();
                $t->guest_id = $user->id;
                $t->tag_name = $tag;
                $t->save();
            }
            $tags = array();
            foreach ( $request->tags as $tag_name) {
                $tag = DB::table('tags')->whereRaw("tags.name = '$tag_name'")->get();
                if (count($tag))
                    $tags[] = $tag[0];
            }
            $user->tags = $tags;
        }

        // $response = array();
        // $response['id'] = $user->id;
        // $response['token'] = $user->token;
        $staff_id = isset($request->headers->user) ? $request->headers->user->id : 0;
        $this->sendNotification('GuestCreated', $staff_id,  $user->id, Carbon::now());

        return $this->jsonResponse('STATUS_SUCCESS', $user, 'user_create_success');
    }
    /**
     * @SWG\Post(
     *     path="/app/login",
     *     tags={"Guests"},
     *     summary="Login by phone",
     *     description="",
     *     operationId="api.guest.login",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          in="body",
     *          name="body",
     *          description="",
     *          required=true,
     *          @SWG\Schema(
     *              type="object",
     *             @SWG\Property(
     *                  property="phone",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *              @SWG\Property(
     *                  property="code",
     *                  type="string",
     *                  default="768543",
     *                  example="",
     *              )
     *          )
     *     ),
     *     @SWG\Response(
     *         response=200,
     *         description="Logged in successfully"
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */
    public function login_with_code(Request $request)
    {
        $code = $request->code;
        $phone = $request->phone;
        $sms = Sms::where(['phone_number' => $request->phone])
                    ->where('created_at', '>', Carbon::now()->subMinutes(10))
                    ->orderBy('created_at', 'desc')->first();
        if (isset($code)) {
            if (isset($sms)) {
                if ($code == $sms->sms_code) {
                    $user = Guest::where('phone', '=', $request->phone)->first();
                    if(empty($user)) {
                        return $this->failResponse('STATUS_INTERNAL_SERVER_ERROR', 'invalid_user');
                    }
                    $user->token = JWTAuth::fromUser($user);
                    $user->save();
                    $response = array();
                    $response['id'] = $user->id;
                    $response['name'] = $user->name;
                    $response['email'] = $user->email;
                    $response['wechat_account'] = $user->wechat_account;
                    $response['alipay_accoun_id'] = $user->alipay_accoun_id;
                    $response['alipay_account_name'] = $user->alipay_account_name;
                    $response['token'] = $user->token;
                    return $this->jsonResponse('STATUS_SUCCESS', $response);             
                } else {
                    return $this->failResponse('STATUS_LOGIC_ERROR', 'login_incorrect_code');
                }
            } else {
                return $this->failResponse('STATUS_LOGIC_ERROR', 'sms_code_expire_limit');
            }
        } else {
            return $this->failResponse('STATUS_LOGIC_ERROR', 'sms_code_required');
        }   
    }
    /**
     * @SWG\Get(
     *     path="/app/guests/{id}",
     *     tags={"Guests"},
     *     summary="Get a guestinfo",
     *     description="",
     *     operationId="api.guest.guestinfo",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          name="id",
     *          in="path",
     *          description="Get guest by ID",
     *          required=true,
     *          type="integer"
     *     ),
     *     @SWG\Parameter(
     *          name="token",
     *          in="query",
     *          description="Token",
     *          required=true,
     *          type="integer"
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="successful operation",
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */    
    public function getGuest($id, Request $request) {

        $this->setLang($request->lang);   

        if (!$request->headers->user->authorizeRoles('guest')) {
            return $this->failResponse('STATUS_FORRBIDDEN', 'user_not_permission');
        }
     
        $guest = Guest::find($id);
        if ($guest) {

            $tag_names = DB::table('guests')
            ->leftJoin('guest_tags', 'guests.id', '=', 'guest_tags.guest_id')
            ->select('guest_tags.tag_name')
            ->whereRaw("guests.id = '$id'")
            ->get(); 

            if (count($tag_names)) {
                $tags = array();
                foreach ( $tag_names as $tag_name) {
                    $tag = DB::table('tags')->whereRaw("tags.name = '$tag_name->tag_name'")->get();
                    if (count($tag))
                        $tags[] = $tag[0];
                }
                $guest['tags'] = $tags;
            }


            return $this->jsonResponse('STATUS_SUCCESS', $guest);
        } else {
            return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }  
    }
    /**
     * @SWG\Get(
     *     path="/app/guests",
     *     tags={"Guests"},
     *     summary="Get all guestinfo",
     *     description="",
     *     operationId="api.guest.guestallinfo",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          name="token",
     *          in="query",
     *          description="Token",
     *          required=true,
     *          type="integer"
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="successful operation",
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */    
    public function getAllGuest(Request $request) {

        $this->setLang($request->lang);    

        if (!$request->headers->user->authorizeRoles('guest')) {
            return $this->failResponse('STATUS_FORRBIDDEN', 'user_not_permission');
        }

        $search = $request->query('s');    
        if ($search) {
            $guests = Guest::whereRaw(
                "name like '%$search%'
                or email like '%$search%'
                or company_name like '%$search%'
                or phone like '%$search%'"
            )->get();
        } else {
            $guests = Guest::all();
        }    

        if (count($guests)) {
            foreach ( $guests as &$guest) {
                $id = $guest->id;
                $tag_names = DB::table('guests')
                ->leftJoin('guest_tags', 'guests.id', '=', 'guest_tags.guest_id')
                ->select('guest_tags.tag_name')
                ->whereRaw("guests.id = '$id'")
                ->get(); 
    
                if (count($tag_names)) {
                    $tags = array();
                    foreach ( $tag_names as $tag_name) {
                        $tag = DB::table('tags')->whereRaw("tags.name = '$tag_name->tag_name'")->get();
                        if (count($tag))
                            $tags[] = $tag[0];
                    }
                    $guest['tags'] = $tags;
                }
            }
            return $this->jsonResponse('STATUS_SUCCESS', $guests);
        } else {
            return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }  
    }

    /**
     * @SWG\Put(
     *     path="/app/guests/{id}",
     *     tags={"Guests"},
     *     summary="Update a guestinfo",
     *     description="",
     *     operationId="api.guest.updateguest",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          name="id",
     *          in="path",
     *          description="ID",
     *          required=true,
     *          type="integer"
     *     ),
     *     @SWG\Parameter(
     *          in="body",
     *          name="body",
     *          description="",
     *          required=true,
     *          @SWG\Schema(
     *              type="object",
     *              @SWG\Property(
     *                  property="name",
     *                  type="string",
     *                  default="vlad",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="email",
     *                  type="string",
     *                  default="my@email.com",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="phone",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="wechat_account",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="company_name",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="alipay_accoun_id",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *             @SWG\Property(
     *                  property="alipay_account_name",
     *                  type="string",
     *                  default="",
     *                  example="",
     *              ),
     *          )
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="successful operation",
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */    
    public function updateGuest($id, Request $request) {

        $this->setLang($request->lang);    

        if (!$request->headers->user->authorizeRoles('guest', 1)) {
            return $this->failResponse('STATUS_FORRBIDDEN', 'user_not_permission');
        }
        $guest = Guest::find($id); 
        if ($guest) {
                $validator = $this->checkValidation($request,'guest_update_rules');
                if ($validator->fails()) {
                    return $this->validationFailResponse('STATUS_LOGIC_ERROR', $validator->errors());
                }
                // validation for unique email & phone
                $user = Guest::whereRaw("email = '$request->email' and id != '$id'"
                                        )->get();
                if (count($user)) {
                    return $this->validationFailResponse1('STATUS_NOT_FOUND', 'email.unique');                    
                }
                $user = Guest::whereRaw("phone = '$request->phone' and id != '$id'"
                                        )->get();
                if (count($user)) {
                    return $this->validationFailResponse1('STATUS_NOT_FOUND', 'phone.unique');                
                }
                $this->copyObject($guest, $request->except('lang', 'token', 'tags'));
                $guest->save();
                $tags = $request->tags;
                if (count ($tags)) {
                    GuestTags::where('guest_id', '=', $guest->id)->delete();
                    foreach ($tags as $tag) {
                        $t = new GuestTags();
                        $t->guest_id = $guest->id;
                        $t->tag_name = $tag;
                        $t->save();
                    }
                } else {
                    GuestTags::where('guest_id', '=', $guest->id)->delete();                    
                }
                
                $staff_id = isset($request->headers->user) ? $request->headers->user->id : 0;
                $this->sendNotification('GuestChanged', $staff_id,  $guest->id, Carbon::now());

                return $this->jsonResponse('STATUS_SUCCESS', null, 'user_update_success');
            } else {
                return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }
    }
    /**
     * @SWG\Put(
     *     path="/app/block_guests/{id}",
     *     tags={"Guests"},
     *     summary="block a guestinfo",
     *     description="",
     *     operationId="api.guest.blockguest",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          name="id",
     *          in="path",
     *          description="ID",
     *          required=true,
     *          type="integer"
     *     ),
     *     @SWG\Parameter(
     *          in="body",
     *          name="body",
     *          description="",
     *          required=true,
     *          @SWG\Schema(
     *              type="object",
     *              @SWG\Property(
     *                  property="is_block",
     *                  type="string",
     *                  default="vlad",
     *                  example="",
     *              ),
     *          )
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="successful operation",
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */    
    public function blockGuest($id, Request $request) {

        if (!$request->headers->user->authorizeRoles('guest', 1)) {
            return $this->failResponse('STATUS_FORRBIDDEN', 'user_not_permission');
        }
        
        $this->setLang($request->lang);    
        
        $guest = Guest::find($id); 
        if ($guest) {
                $guest->is_block = $request->is_block;
                $guest->save();
                return $this->jsonResponse('STATUS_SUCCESS', $guest, 'user_update_success');
            } else {
                return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }
    }
        /**
     * @SWG\Delete(
     *     path="/app/guests/{id}",
     *     tags={"Admin"},
     *     summary="Delete a guest",
     *     description="",
     *     operationId="api.app.deleteguest",
     *     produces={"application/json"},
     *     @SWG\Parameter(
     *          name="id",
     *          in="path",
     *          description="ID",
     *          required=true,
     *          type="string"
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="successful operation",
     *     ),
     *     @SWG\Response(
     *         response=500,
     *         description="Server Error"
     *     ),
     *     @SWG\Response(
     *         response=400,
     *         description="Logic Error"
     *     )
     * )
     */
    public function deleteGuest($id, Request $request) {

        $this->setLang($request->lang);    

        if (!$request->headers->user->authorizeRoles('guest', 1)) {
            return $this->failResponse('STATUS_FORRBIDDEN', 'user_not_permission');
        }

        $guest = Guest::find($id);
        if ($guest) {
            GuestTags::where('guest_id', '=', $guest->id)->delete();            

            $staff_id = isset($request->headers->user) ? $request->headers->user->id : 0;
            $this->sendNotification('GuestDeleted', $staff_id,  $guest->id, Carbon::now());

            $guest->delete();
            return $this->jsonResponse('STATUS_SUCCESS', null, 'user_delete_success');
        } else {
            return $this->failResponse('STATUS_NOT_FOUND', 'user_not_found');
        }    
    }
}
