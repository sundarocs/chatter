<?php

namespace App\Http\Controllers;
namespace DevDojo\Chatter\Controllers;

use Auth;
use DevDojo\Chatter\Models\Models;
use Illuminate\Routing\Controller as Controller;
use App\Comment;
use App\Event;
use App\Group;
use App\Hashtag;
use App\Http\Requests;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Media;
use App\Notification;
use App\Page;
use App\Repositories\UserRepository;
use App\Role;
use App\Setting;
use App\Timeline;
use App\User;
use Carbon\Carbon;
use Flash;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Prettus\Repository\Criteria\RequestCriteria;
use Response;
use Facuz\Theme\Facades\Theme;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Route;

class ChatterController extends Controller
{
    public function index(Request $request, $slug = '')
    {
        $reportedId         = array();
        $pagination_results = config('chatter.paginate.num_of_results');
        $reportedId = array();
        if(!Auth::guest()){
            $reportedId         = DB::table('chatter_discussion_reports')->where('comment_id', '=', NULL)->where('reporter_id', '=', Auth::user()->id)->pluck('discussion_id');
        }
        $currentUri = $request->path();
        $discussions = Models::discussion()->whereNotIn('id', $reportedId)->with('user')->with('post')->with('postsCount')->with('category')->with('discussionvote')->where('status', '=', 1);
        $keywords   =   $request->get('keyword');

        $discussions = $discussions->select('chatter_discussion.*');
        $selectRaw   = '( CASE WHEN TIMESTAMPDIFF(DAY,start_date,"'. Carbon::now() .'") <= 7
        THEN 
        start_date
        ELSE 
        CASE WHEN 
        (SELECT created_at  FROM `chatter_post` WHERE `chatter_discussion_id` = chatter_discussion.id and  deleted_at is null ORDER BY `id` ASC LIMIT 1,1) IS NOT null
        THEN 
        TIMESTAMPADD(DAY,-7,(SELECT created_at  FROM `chatter_post` WHERE `chatter_discussion_id` = chatter_discussion.id and  deleted_at is null ORDER BY `id` ASC LIMIT 1,1))
        END 
        END )
        as Timediff';

        $discussions = $discussions->selectRaw($selectRaw);

        if ($keywords) {
            $discussions = $discussions->whereRaw("( EXISTS(select * from `chatter_post` where `chatter_post`.`chatter_discussion_id` = `chatter_discussion`.`id` and `chatter_post`.`body` LIKE '%".$keywords."%') OR `title` LIKE '%".$keywords."%' )");
            /*$discussions = $discussions->whereHas('post', function($query) use($keywords){
                $query->where('chatter_post.body','LIKE','%'.$keywords.'%');
            })->where('title', 'like', '%'.$keywords.'%');*/
        }
        
        if ($currentUri == "forums/past-discussion") {
            $discussions = $discussions->whereRaw("end_date <='" . Carbon::now() . "'");
        } else {
            $discussions = $discussions->whereRaw("end_date >='" . Carbon::now() . "' AND start_date <='" . Carbon::now() . "'");
        }

        //echo $discussions->toSql(); exit;
        //$discussions = $discussions->orderBy('start_date', 'DESC')->paginate($pagination_results);

        $discussions = $discussions->orderBy(DB::raw('IF(Timediff,Timediff,start_date)'), 'DESC')->paginate($pagination_results);

        // $discussions->orderByRaw('IF(Timediff,Timediff,start_date) DESC')->paginate($pagination_results);

        //$discussions = $discussions->reports->detach([Auth::user()->id]);
        /*if (isset($slug)) {
            $category = Models::category()->where('slug', '=', $slug)->first();
            if (isset($category->id)) {
                $discussions = Models::discussion()->with('user')->with('post')->with('postsCount')->with('category')->where('chatter_category_id', '=', $category->id)->orderBy('created_at', 'DESC')->paginate($pagination_results);
            }
        }*/

        $categories = Models::category()->all();
        $chatter_editor = config('chatter.editor');

        // Dynamically register markdown service provider
        \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');
        $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');

        $theme->setTitle(setPageTitle(trans('common.discussion_forum')));

        return $theme->scope('forum/home', compact('discussions', 'categories', 'chatter_editor', 'currentUri', 'keywords'))->render();

        //return view('chatter::home', compact('discussions', 'categories', 'chatter_editor'));
    }

    public function placeLikeOrUnlikeOnForum(Request $request)
    {
        $postInfo   = $request->input('postInfo');
        if (!empty($postInfo)) {
            $postDetail  = explode("-",$postInfo);
            $action      = $postDetail[0]; 
            $postId      = $postDetail[1]; 
            $postOwnerId = $postDetail[2]; 

            if ($postOwnerId != Auth::user()->id) { //NOTE: DONT ALLOW POST OWNER TO LIKE HIS / HER OWN POST

                //CHECK WHETHER USER VOTE ALREADY EXIST
                $voteInfo = Models::discussionvote()->where('discussion_id', '=', $postId)->where('user_id', '=', Auth::user()->id)->first();

                //GET DISCUSSION LIKE COUNT
                $discussionInfo = Models::discussion()->select(['likecount','user_id','id','slug','chatter_category_id'])->where('id', '=', $postId)->first();
                $likeCount      = $discussionInfo->likecount; 

                //CALCULATE NEW COUNT || NOTE : 1-UP and 2-DOWN
                if (!empty($voteInfo)) {
                    if ($voteInfo->vote == $action) {
                        if ($action == 1) {
                            $notify_type = 'removed_upvote_forum';
                            $notify_message = trans('messages.removed_upvote_forum');
                        } else{
                            $notify_type = 'removed_downvote_forum';
                            $notify_message = trans('messages.removed_downvote_forum');
                        }
                        //THIS PROCESS IS FOR IF USER CLICKS AGAIN ON SAME VOTE
                        $action == 1 ? $likeCount-- : $likeCount++;
                        $newLikeCount   = $likeCount;
                    } else {
                        // This logic is for changing the vote of already voted discussion.
                        $newLikeCount = ($action == 1) ? (int)$likeCount+2 : (int)$likeCount-2;
                        if ($action == 1) {
                            $notify_type = 'upvote_forum';
                            $notify_message = trans('messages.notify_upvote_forum');
                        } else{
                            $notify_type = 'downvote_forum';
                            $notify_message = trans('messages.notify_downvote_forum');
                        }
                    }
                } else {
                    $action == 1 ? $likeCount++ : $likeCount--;
                    $newLikeCount   = $likeCount;
                    if ($action == 1) {
                        $notify_type = 'upvote_forum';
                        $notify_message = trans('messages.notify_upvote_forum');
                    } else{
                        $notify_type = 'downvote_forum';
                        $notify_message = trans('messages.notify_downvote_forum');
                    }
                }

                $category = Models::category()->find($discussionInfo->chatter_category_id);
                if (!isset($category->slug)) {
                    $category = Models::category()->first();
                }

                $activity['id']     =  $postId;
                $activity['type']   = 'forum';
                $activity['text']   = 'activity.'.$notify_type;
                $activity['action'] = $notify_type;
                $activity['url']    = url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussionInfo->slug );
                Auth::user()->logUserActivity($activity);

                //ADD A RECORD
                if (!empty($voteInfo) && $voteInfo->vote == $action) {
                    Models::discussionvote()->where('id', $voteInfo->id)->delete();
                } else {
                    if (empty($voteInfo)) {
                        Models::discussionvote()->create(['discussion_id' => $postId, 'user_id' => Auth::user()->id, 'vote' => $action, 'ip' => $request->ip()]);
                    } else {
                        Models::discussionvote()->where('id', $voteInfo->id)->update(['vote' => $action]);
                    }
                }
                $notification_id = 0;
                if ($notify_type == 'upvote_forum') { //|| $notify_type == 'downvote_forum' to Avoid Notification for DownVote.
                    $notify_exists = Notification::where(['user_id' => $discussionInfo->user_id, 'notified_by' => Auth::user()->id, 'discussion_id' => $discussionInfo->id, 'type' => $notify_type])->first();
                    if (!$notify_exists) {
                        $notify_data =  Notification::createNotification([
                            'user_id' => $discussionInfo->user_id, 
                            'discussion_id' => $discussionInfo->id, 
                            'notified_by' => Auth::user()->id, 
                            'description' => ucwords(Auth::user()->name) .' '. $notify_message,
                            'type' => $notify_type
                        ]);
                        $notification_id = $notify_data->id;
                    } else {
                        $notification_id = $notify_exists->id;

                        $notify_exists->description = ucwords(Auth::user()->name) .' '. $notify_message;
                        $notify_exists->updated_at = Carbon::now();
                        $notify_exists->save();
                    }
                }

                //GET USER VOTE AFTER ALL THE PROCESS
                $voteInfoAfterUpdate = Models::discussionvote()->where('discussion_id', '=', $postId)->where('user_id', '=', Auth::user()->id)->first();
                $latestVoteFlag = 0;
                if (!empty($voteInfoAfterUpdate)) {
                    $latestVoteFlag = 1;
                }

                //UPDATE LIKE COUNT
                Models::discussion()->where('id', $postId)->update(['likecount' => $newLikeCount]);

                //RESPONSE TO AJAX REQUEST
                $resultInfo = array("likecount"     => $newLikeCount,
                                    "userstatus"    => $latestVoteFlag,
                                    "requeststatus" => 200,
                                    "message"       => "Success",
                                    'notification_id' => $notification_id,
                                    );
            } else {
                $resultInfo = array("requeststatus" => 201,
                                    "message"    => trans('messages.vote_not_allowed')
                                    );
            }
        } else {
            $resultInfo = array("requeststatus" => 201,
                                "message"    => trans('messages.forums_default_error')
                                );
        }
        echo json_encode($resultInfo);
    }

    public function login()
    {
        if (!Auth::check()) {
            return \Redirect::to('/'.config('chatter.routes.login').'?redirect='.config('chatter.routes.home'))->with('flash_message', 'Please create an account before posting.');
        }
    }

    public function register()
    {
        if (!Auth::check()) {
            return \Redirect::to('/'.config('chatter.routes.register').'?redirect='.config('chatter.routes.home'))->with('flash_message', 'Please register for an account.');
        }
    }
}
