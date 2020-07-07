<?php

namespace DevDojo\Chatter\Controllers;

use Auth;
use Carbon\Carbon;
use DevDojo\Chatter\Events\ChatterAfterNewResponse;
use DevDojo\Chatter\Events\ChatterBeforeNewResponse;
use DevDojo\Chatter\Mail\ChatterDiscussionUpdated;
use DevDojo\Chatter\Models\Models;
use DevDojo\Chatter\Models\ChatterLikes;
use Event;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as Controller;
use Illuminate\Support\Facades\Mail;
use Validator;
use Facuz\Theme\Facades\Theme;
use App\Setting;
use App\Notification;
use Illuminate\Support\Facades\Session;
use DB;
use App\Services\Sanitizer;
use LaravelEmojiOne;
use App\Timeline;
use Intervention\Image\Facades\Image;
use Storage;
use App\Media;
use App\User;
use App\Post;

class ChatterPostController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $total = 10;
        $offset = 0;
        if ($request->total) {
            $total = $request->total;
        }
        if ($request->offset) {
            $offset = $request->offset;
        }
        $posts = Models::post()->with('user')->orderBy('created_at', 'DESC')->take($total)->offset($offset)->get();

        return response()->json($posts);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //printArray($request->all()); exit;
        $stripped_tags_body = ['body' => strip_tags($request->body)];
        $validator = Validator::make($stripped_tags_body, [
            'body' => 'required',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json(['status' => '201', 'messages' => $errors->get('body')]);
        }
        if (!$request->comment_id) {
            if (config('chatter.security.limit_time_between_posts')) {
                if ($this->notEnoughTimeBetweenPosts()) {
                    $minute_copy = (config('chatter.security.time_between_posts') == 1) ? ' minute' : ' minutes';
                    $chatter_alert = 'In order to prevent spam, please allow at least '.config('chatter.security.time_between_posts').$minute_copy.' in between submitting content.';

                    return response()->json(['status' => '201', 'messages' => $chatter_alert]);
                }
            }
        }

        $request->request->add(['user_id' => Auth::user()->id]);
        if (config('chatter.editor') == 'simplemde'):
            $request->request->add(['markdown' => 1]);
        endif;
        
        $discussion = Models::discussion()->find($request->chatter_discussion_id);
        if ($discussion->end_date >= \Carbon\Carbon::now()) {
            $insertData = ['chatter_discussion_id' => $request->chatter_discussion_id,
                           'body' => getPlainDescription($request->body),
                           'user_id' => $request->user_id,
                           'markdown' => $request->markdown
                          ];
        } else {
            return response()->json(['status' => '201', 'messages' => 'Discussion time expired.']);
        }
        if ($request->comment_id) {
            $insertData['parent_id'] = $request->comment_id;
        } else {
            $insertData['parent_id'] = NULL;
        }
        
        $new_post = Models::post()->create($insertData);

        // $new_post = Models::post()->create([
        //     'chatter_discussion_id'     => $insertData['chatter_discussion_id'],
        //     'user_id'                   => $insertData['user_id'],
        //     'body'                      => $insertData['body'],
        //     'parent_id'                 => $insertData['parent_id'],
        //     'markdown'                  => $insertData['markdown'],
        // ]);


        if (Auth::user()->type == 'ambassador') {
                teamMembernotification($discussion, 'comment_forum',$request->comment_id);
        }
        $notificationId = "";

        if ($request->comment_id) {
            $commentUser = Models::post()->with('user')->find($request->comment_id);
            $replyUser   =  Models::post()->with('user')->find($new_post->id);
            if ($commentUser->user_id != $replyUser->user_id) {
                $notify_type    = 'forum_reply';
                $notify_message = trans('messages.reply_on_forum');
                
                $notify_data = Notification::createNotification([
                    'user_id' => $commentUser->user_id, 
                    'discussion_id' => $discussion->id, 
                    'notified_by' => $replyUser->user_id, 
                    'description' => ucwords($replyUser->user->name) .' '. $notify_message, 
                    'discussion_comment_id'=> $new_post->id,
                    'type' => $notify_type
                ]);
                $notificationId = $notify_data->id;
            }
        }

        $category = Models::category()->find($discussion->chatter_category_id);
        if (!isset($category->slug)) {
            $category = Models::category()->first();
        }

        if ($new_post->id) {
            Event::fire(new ChatterAfterNewResponse($request));
            if (function_exists('chatter_after_new_response')) {
                chatter_after_new_response($request);
            }

            $discussion_comment_count = 0;
            if (isset($discussion->postsCount[0]->total)) {
                $discussion_comment_count = $discussion->postsCount[0]->total;
            }
            // if email notifications are enabled
            if (config('chatter.email.enabled')) {
                // Send email notifications about new post
                $this->sendEmailNotifications($new_post->discussion);
            }

            //TO GET TOTAL COMMENTS
            $discussionInfo  = Models::discussion()->with('postsCount')->find($request->chatter_discussion_id);
            $totlaComment    = 0;
            if (isset($discussionInfo->postsCount[0]->total)) {
                $totlaComment = $discussionInfo->postsCount[0]->total;
            }

            $activity['id']     =  $discussionInfo->id;
            $activity['type']   = 'forum';
            $activity['text']   = 'activity.comment_discussion';
            $activity['action'] = 'comment_discussion';
            $activity['url']    =  url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussionInfo->slug );
            $activity['data']   = ['url' => url($activity['url']), 'comment_id'=> $new_post->id ];
            
            Auth::user()->logUserActivity($activity);

            $chatter_alert = 'Response successfully submitted to '.config('chatter.titles.discussion').'.';
            \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');
            $post        = Models::post()->with('user')->find($new_post->id);
            if ($new_post->parent_id) {
                $parentPost = Models::post()->with('user')->find($new_post->parent_id);
                $totlaComment = $parentPost->comments()->count();
            }
            $theme       = Theme::uses(Setting::get('current_theme', 'default'))->layout('ajax');
            $postHtml    = $theme->scope('forum/comment', compact('post'))->render();
            return response()->json(['status' => '200', 'data' => $postHtml, 'message' => $chatter_alert, 'total_comment' => $totlaComment, 'discussion_comment_count' => $discussion_comment_count, 'notification_id' => $notificationId,'parent_id' =>$new_post->parent_id]);
        } else {
            $chatter_alert = 'Sorry, there seems to have been a problem submitting your response.';
            return response()->json(['status' => '201', 'messages' => $chatter_alert]);
        }
    }
    
    // public function store_old(Request $request)
    // {


    //     $stripped_tags_body = ['body' => strip_tags($request->body)];
    //     $validator = Validator::make($stripped_tags_body, [
    //         'body' => 'required|min:10',
    //     ]);

    //     /*Event::fire(new ChatterBeforeNewResponse($request, $validator));
    //     if (function_exists('chatter_before_new_response')) {
    //         chatter_before_new_response($request, $validator);
    //     }*/

    //     if ($validator->fails()) {
    //         return back()->withErrors($validator)->withInput();
    //     }

    //     if (config('chatter.security.limit_time_between_posts')) {
    //         if ($this->notEnoughTimeBetweenPosts()) {
    //             $minute_copy = (config('chatter.security.time_between_posts') == 1) ? ' minute' : ' minutes';
    //             $chatter_alert = [
    //                 'chatter_alert_type' => 'danger',
    //                 'chatter_alert'      => 'In order to prevent spam, please allow at least '.config('chatter.security.time_between_posts').$minute_copy.' in between submitting content.',
    //                 ];

    //             return back()->with($chatter_alert)->withInput();
    //         }
    //     }

    //     $request->request->add(['user_id' => Auth::user()->id]);

    //     if (config('chatter.editor') == 'simplemde'):
    //         $request->request->add(['markdown' => 1]);
    //     endif;

    //     $new_post = Models::post()->create($request->all());


    //     $discussion = Models::discussion()->find($request->chatter_discussion_id);
    //     if( $discussion->user_id!= Auth::user()->id) {
    //        $notify_type = 'comment_forum';
    //        $notify_message = trans('messages.commented_on_forum');
    //        $notify_data =  Notification::create(['user_id' => $discussion->user_id, 'discussion_id' => $discussion->id, 'notified_by' => Auth::user()->id, 'description' => Auth::user()->name.' '.$notify_message, 'type' => $notify_type]);
    //        Notification::sendNotification($notify_data);
    //     }

    //     $category = Models::category()->find($discussion->chatter_category_id);
    //     if (!isset($category->slug)) {
    //         $category = Models::category()->first();
    //     }

    //     if ($new_post->id) {
    //         Event::fire(new ChatterAfterNewResponse($request));
    //         if (function_exists('chatter_after_new_response')) {
    //             chatter_after_new_response($request);
    //         }

    //         // if email notifications are enabled
    //         if (config('chatter.email.enabled')) {
    //             // Send email notifications about new post
    //             $this->sendEmailNotifications($new_post->discussion);
    //         }

    //         $chatter_alert = [
    //             'chatter_alert_type' => 'success',
    //             'chatter_alert'      => 'Response successfully submitted to '.config('chatter.titles.discussion').'.',
    //             ];

    //         return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$discussion->slug)->with($chatter_alert);
    //     } else {
    //         $chatter_alert = [
    //             'chatter_alert_type' => 'danger',
    //             'chatter_alert'      => 'Sorry, there seems to have been a problem submitting your response.',
    //             ];

    //         return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$discussion->slug)->with($chatter_alert);
    //     }
    // }

    private function notEnoughTimeBetweenPosts()
    {
        $user = Auth::user();

        $past = Carbon::now()->subMinutes(config('chatter.security.time_between_posts'));

        $last_post = Models::post()->where('user_id', '=', $user->id)->where('created_at', '>=', $past)->first();

        if (isset($last_post)) {
            return true;
        }

        return false;
    }

    private function sendEmailNotifications($discussion)
    {
        $users = $discussion->users->except(Auth::user()->id);
        foreach ($users as $user) {
            Mail::to($user)->queue(new ChatterDiscussionUpdated($discussion));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $stripped_tags_body = ['body' => strip_tags($request->body)];
        $validator = Validator::make($stripped_tags_body, [
            'body' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $post = Models::post()->find($id);
        if (!Auth::guest() && (Auth::user()->id == $post->user_id)) {
            $post->body = strip_tags($request->body);
            $post->save();

            $discussion = Models::discussion()->find($post->chatter_discussion_id);

            $category = Models::category()->find($discussion->chatter_category_id);
            if (!isset($category->slug)) {
                $category = Models::category()->first();
            }

            $chatter_alert = [
                'chatter_alert_type' => 'success',
                'chatter_alert'      => 'Successfully updated the '.config('chatter.titles.discussion').'.',
                ];

            return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug.'/'.$discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = [
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => 'Nah ah ah... Could not update your response. Make sure you\'re not doing anything shady.',
                ];

            return redirect('/'.config('chatter.routes.home'))->with($chatter_alert);
        }
    }

    /**
     * Delete post.
     *
     * @param string $id
     * @param  \Illuminate\Http\Request
     *
     * @return \Illuminate\Routing\Redirect
     */
    public function destroy($id, Request $request)
    {
        $post = Models::post()->with('discussion')->findOrFail($id);

        if ($request->user()->id !== (int) $post->user_id) {
            return redirect('/'.config('chatter.routes.home'))->with([
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => 'Nah ah ah... Could not delete the response. Make sure you\'re not doing anything shady.',
            ]);
        }

        if ($post->discussion->posts()->oldest()->first()->id === $post->id) {
            // Delete badwords record - Sanitizer
            $discussion_id = $id;
            $sanitizer = new Sanitizer();
            $sanitizer->deleteLogContent($post->chatter_discussion_id, 'discussion_title');
            $sanitizer->deleteLogContent($post->id, 'discussion', $post->chatter_discussion_id); // 3rd param to delete discussion with its comments entries

            $post->discussion->posts()->delete();
            $post->discussion()->delete();
            $medias = DB::table('chatter_media')
                ->join('media', 'media.id', '=', 'chatter_media.media_id')->where('media.type', '=', 'image')->select('media.*')->where('chatter_media.chatter_discussion_id', '=', $discussion_id)->get();
            if ($medias) {
                foreach ($medias as $media) {
                    $imageDeatils = Media::where('id',$media->id)->first();
                    if ($imageDeatils) {
                        if($imageDeatils->source) {
                            Post::removePostImage($imageDeatils->source);
                        }
                        $imageParentDeatils = DB::table('post_media')->where('media_id', $media->id)->delete();
                        $imageDeatils = Media::where('id',$media->id)->first();
                        $imageDeatils->delete();
                    }
                }
            }

            $activity['id']     = $id;
            $activity['type']   = 'forum';
            $activity['text']   = 'activity.deleted_discussion';
            $activity['action'] = 'delete_discussion';
            $activity['url']    = url('/');
            Auth::user()->logUserActivity($activity);
            
            return redirect('/'.config('chatter.routes.home'))->with([
                'chatter_alert_type' => 'success',
                'chatter_alert'      => 'Successfully deleted the response and '.strtolower(config('chatter.titles.discussion')).'.',
            ]);
        }

        $post->delete();


        

        // Delete badwords record - Sanitizer
        $sanitizer = new Sanitizer();
        $sanitizer->deleteLogContent($post->chatter_discussion_id, 'discussion_title');
        $sanitizer->deleteLogContent($post->id, 'discussion', $post->chatter_discussion_id); // 3rd param to delete discussion with its comments entries


        $url = '/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$post->discussion->category->slug.'/'.$post->discussion->slug;

        return redirect($url)->with([
            'chatter_alert_type' => 'success',
            'chatter_alert'      => 'Successfully deleted the response from the '.config('chatter.titles.discussion').'.',
        ]);
    }

    public function edit($slug)
    {
        $chatter_editor = config('chatter.editor');
        $discussion = Models::discussion()->where('slug', '=', $slug)->first();
        if (is_null($discussion)) {
            abort(404);
        }

        $forumMedia = DB::table('chatter_media')
            ->join('media', 'media.id', '=', 'chatter_media.media_id')->where('media.type', '=', 'image')->select('media.*')->where('chatter_media.chatter_discussion_id', '=', $discussion->id)->get();
        $discussion->media = $forumMedia;

        $discusion_det = Models::post()->with('user')->where('chatter_discussion_id', '=', $discussion->id)->orderBy('created_at', 'ASC')->take(1)->offset(0)->get();
        $title = 'no record found';
        $slug  = '';
        if ($discussion->title) {
            $title = LaravelEmojiOne::toImage(nl2br(anchor_link_Add(e(getDecodedContent($discussion->title)), 'view')));
            $slug = $discussion->slug;
        }
        // Dynamically register markdown service provider
        \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');
        $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        $theme->breadcrumb()->add(array(
            array(
                'label' => 'Home',
                'url' => '',
            ),
            array(
                'label' => trans('common.discussion_forums'),
                'url' => url('forums'),
            ),
            array(
                'label' =>  $title,
                'url' => url('forums/discussion/introductions/'.$slug),
            ),
            array(
                'label' =>  'edit',
                'url' => url(''),
            )
        ));

        $theme->setTitle(trans('common.general_settings').' '.Setting::get('title_seperator').' '.Setting::get('site_title').' '.Setting::get('title_seperator').' '.Setting::get('site_tagline'));
        return $theme->scope('forum/edit', compact('discusion_det','chatter_editor','discussion'))->render();
    }
    public function updateDiscussion(Request $request, Sanitizer $sanitizer, $id)
    {
        $request->request->add(['body_content' => strip_tags($request->body)]);
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body_content' => 'required',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        //GET DISCUSSION INFO
        $discussion = Models::discussion()->find($id);

        if (!Auth::guest() && (Auth::user()->id == $discussion->user_id)) {

            //UPDATE DISCUSSION TITLE
            $discussion->title = $request->title;
            //$discussion->slug  = str_slug($request->title); //Do not change slug when edit a discussion
            $discussion->save();

            //GET POST INFO AND UPDATE
            $post = Models::post()->where('chatter_discussion_id', '=', $id)->first();
            $post->body = strip_tags($request->body_content);
            $post->save();

            if ($request->input('preload_images')) {
                foreach ($request->input('preload_images') as $deleteImageId) {
                    $imageDeatils = Media::where('id', $deleteImageId)->first();
                    if ($imageDeatils->source) {
                        Post::removePostImage($imageDeatils->source);
                    }
                    $imageParentDeatils = DB::table('chatter_media')->where('media_id', $deleteImageId)->delete();
                    $imageDeatils       = Media::where('id', $deleteImageId)->first();
                    $imageDeatils->delete();
                }
            }

            if ($request->forum_images_upload_modified) {
                foreach ($request->forum_images_upload_modified as $postImage) {


                    $photoName = getFormattedPostImageName(basename($postImage), $post->id);

                    //$photoName = Timeline::getImageName($postImage, $post->id);
                    $post_image_name_path = getPostImageNamePath($photoName);
                    // Call to post image upload and resize code
                    Post::uploadForumPostImage($postImage, $post_image_name_path, $upload_type = 'move');
                    $image_base_url = storage_url(Post::FORUM_POST_IMAGE_PATH . $post_image_name_path);
                    // Check image nudity
                    $isNudity = $sanitizer->checkImage('', $image_base_url);

                    $image_status = 1;
                    if ($isNudity) {
                        $nudity_count++;
                        $image_status = 0;
                    }
                    $media = Media::create([
                        'title'  => $photoName,
                        'type'   => 'image',
                        'source' => $post_image_name_path,
                        'active' => $image_status,
                    ]);

                    $reported = DB::table('chatter_media')->insert([
                        'chatter_discussion_id' => $discussion->id, 
                        'media_id'              => $media->id, 
                        'created_at'            => Carbon::now(),
                        'updated_at'            => Carbon::now()
                    ]);

                    //Log image nudity entry
                    if ($isNudity && $media->id) {
                        NudityMediaFilter::create([
                            'user_id'  => Auth::user()->id,
                            'post_id'  => $discussion->id,
                            'media_id' => $media->id,
                        ]);
                    }
                }
            }

            $category = Models::category()->find($discussion->chatter_category_id);
            if (!isset($category->slug)) {
                $category = Models::category()->first();
            }

            $chatter_alert = [
                'chatter_alert_type' => 'success',
                'chatter_alert'      => 'Successfully updated the '.config('chatter.titles.discussion').'.',
                ];

            $activity['id']     =  $discussion->id;
            $activity['type']   = 'forum';
            $activity['text']   = 'activity.edit_discussion';
            $activity['action'] = 'edit_forum';
            $activity['url']    = url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussion->slug );
            Auth::user()->logUserActivity($activity);
            return response()->json(['status' => '200', 'redirect_url' => url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussion->slug), 'message' => $chatter_alert]);
            //return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = [
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => 'Nah ah ah... Could not update your response. Make sure you\'re not doing anything shady.',
                ];
            return response()->json(['status' => '202', 'redirect_url' => url('/'.config('chatter.routes.home')), 'message' => $chatter_alert]);
            //return redirect('/'.config('chatter.routes.home'))->with($chatter_alert);
        }
    }

 
    //EDIT COMMENT
    public function editComment(Request $request)
    {
        $post_id = $request->post_id;
        $comment = getPlainDescription($request->comment);
        $postInfo = Models::post()->find($post_id);
        if (isset($postInfo->body)) {
            $postInfo->body = $comment;
            $postInfo->save();

            //DISCUSSION INFO
            $post = Models::post()->with('discussion')->findOrFail($post_id);
            $url = '/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$post->discussion->category->slug.'/'.$post->discussion->slug;
            if (!(isset($request->ajax) && $request->ajax)) {
                Session::set('chatter_alert_type', 'success');
                Session::set('chatter_alert', 'Successfully updated the comment from the '.config('chatter.titles.discussion').'.');
            }
            $activity['id']     =  $post->discussion->id;
            $activity['type']   = 'forum';
            $activity['text']   = 'activity.edit_discussion_comment';
            $activity['action'] = 'edit_discussion_comment';
            $activity['url']    =  $url;
            $activity['data']   = ['url' => url($url), 'comment_id'=> $post_id ];
            Auth::user()->logUserActivity($activity);
        }
        if ($request->ajax && isset($postInfo->body) && $postInfo->body) {
            return response()->json(['status' => '200', 'comment' => nl2br(e(getDecodedContent($postInfo->body))), 'og_comment' => nl2br(e(getDecodedContent($postInfo->body))), 'message' => 'Comment Updated Successfully', 'redirect' => 0]);
        } elseif ($request->ajax && !(isset($postInfo->body) && $postInfo->body)) {
            return response()->json(['status' => '201', 'url' => $url, 'redirect' => 1]);
        }
        return redirect($url);
        
    }

    public function deleteComment($post_id, Request $request)
    {
        $post = Models::post()->with('discussion')->findOrFail($post_id);
        $url = '/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$post->discussion->category->slug.'/'.$post->discussion->slug;
        $post_id = $post->id;
        if($post->delete()){
            $reply_comment_ids = Models::post()->where("parent_id", '=', $post_id)->pluck('id');
            $delete_reply     = Models::post()->whereIn("id", $reply_comment_ids)->delete();

        }

        $activity['id']     = $post->discussion->id;
        $activity['type']   = 'forum';
        $activity['text']   = 'activity.deleted_discussion_comment';
        $activity['action'] = 'delete_discussion_comment';
        $activity['data']   = ['url' => url($url), 'comment_id'=> $post_id ];
        $activity['url']    =  url($url);
        Auth::user()->logUserActivity($activity);

        Session::put('chatter_alert_type', 'success');
        Session::put('chatter_alert', 'Successfully deleted the comment from the '.config('chatter.titles.discussion').'.');
        return 'success';
    }

    public function reportDiscussion(Request $request)
    {
        $request->merge(array('report_id' => $request->chatter_id));
        $report = Models::discussion()->addReportAbuse($request);
        if ($request) {
            return response()->json(['status' => '200', 'reported' => true, 'message' => trans('messages.success_report')]);
        } else {
            return response()->json(['status' => '201', 'message' => trans('messages.failure_report')]);
        }
    }

    public function reportDiscussionComment(Request $request)
    {
        $request->merge(array('report_id' => $request->comment_id, 'discussion_id' => $request->post_id));
        $report = Models::post()->addReportAbuse($request);

        $discussionInfo  = Models::discussion()->with('postsCount')->find($request->post_id);
        //printArray($discussionInfo);die;
        $discussion_comment_count    = 0;
        if (isset($discussionInfo->postsCount[0]->total)) {
            $discussion_comment_count = $discussionInfo->postsCount[0]->total;
        }

        $post        = Models::post()->with('user')->find($request->comment_id);
        $totlaComment = 0;
        if ($post->parent_id) {
            $parentPost = Models::post()->with('user')->find($post->parent_id);
            $totlaComment = $parentPost->comments()->count();
        }

        if ($request) {
            return response()->json(['status' => '200', 'reported' => true, 'message' => trans('messages.success_report'),'total_comments' => $totlaComment,'discussion_comment_count' => $discussion_comment_count,'parent_id' => $post->parent_id]);
        } else {
            return response()->json(['status' => '201', 'message' => trans('messages.failure_report')]);
        }
    }

    public function commentLike(Request $request)
    {
        

        $notify_type = 'like_forum';
        $comment_id = $request->comment_id;
        
        $unlike = $request->unlike ? $request->unlike : 0;
        $user = Auth::user();
        if ($comment_id) {
            $commentInfo = Models::post()->find($comment_id);

            $postReactions = $commentInfo->getReactions(); 
            $reaction_type = $postReactions[0]->reaction_key;
            $reaction_text = trans('common.'.$reaction_type);
            $reaction_id = $request->reaction_id ? $request->reaction_id : $postReactions[0]->id;

            if ($commentInfo) {
                $likeDetails = Chatterlikes::where(['chatter_post_id' => $commentInfo->id])->where('user_id', Auth::user()->id)->first();
                if ($likeDetails) {
                    if ($unlike) {
                        $likeDetails->forcedelete();
                        $likeMsg = "unliked.";
                        $likeEvent = false;
                        $likeMessage = trans('messages.unliked_discussion_comment');
                    } else {
                        $likeDetails->reaction_id = $reaction_id;
                        $likeDetails->save();
                        $likeMsg = "liked.";
                        $likeEvent = true;
                    }
                } else {
                    $likeDetails = Chatterlikes::addLike([
                        'chatter_post_id' => $commentInfo->id, 
                        'user_id' => $user->id, 
                        'reaction_id' => $reaction_id
                    ]);
                    $likeMsg = "liked.";
                    $likeEvent = true;

                }
                if($likeEvent){
                    /*if ($reaction_id == 1) {
                        $likeMessage = trans('messages.liked_discussion_comment');
                        
                    } else {*/
                    if($commentInfo->parent_id == null ) {
                        $likeMessage = trans('messages.reacted_discussion_comment');
                    } else {
                        $likeMessage = trans('messages.reacted_discussion_reply_comment');   
                    }
                       
                    /*}*/
                }
                if ($commentInfo->user_id != $user->id) {
                    $notify_exists = Notification::where(['user_id' => $commentInfo->user_id, 'notified_by' => $user->id, 'discussion_id' => $commentInfo->chatter_discussion_id, 'discussion_comment_id' => $commentInfo->id, 'type' => $notify_type])->first();
                    if (!$notify_exists) {
                        /*$comment_user  = User::find($commentInfo->user_id);
                        $user_settings = $user->getUserSettings($comment_user->id);
                        if ($user_settings && $user_settings->email_like_comment == 'yes') {
                            $cssLink['theme_url'] = url('/themes/default/assets');
                            Mail::send('emails.commentlikemail', ['user' => $user, 'comment_user' => $comment_user, 'comment_id' => $commentInfo->id, 'cssLink' => $cssLink, 'post' => $commentInfo], function ($m) use ($user, $comment_user, $likeMessage) {
                                $m->from(Setting::get('noreply_email'), Setting::get('site_name'));
                                $m->to($comment_user->email, $comment_user->name)->subject($user->name . ' ' . $likeMessage);
                            });
                        }*/
                        $notify_data =  Notification::createNotification([
                            'user_id' => $commentInfo->user_id, 
                            'discussion_id' => $commentInfo->chatter_discussion_id, 
                            'notified_by' => $user->id, 
                            'description' => ucwords($user->name) .' '. $likeMessage,
                            'type' => $notify_type,
                            'discussion_comment_id'=> $commentInfo->id
                        ]);
                        $notification_id = $notify_data->id;

                    } else {
                        if ($likeEvent) {
                            $notification_id = $notify_exists->id;
                            $notify_exists->description = ucwords($user->name) .' '. $likeMessage;
                            $notify_exists->updated_at = Carbon::now();
                            $notify_exists->save();
                         } else {
                            $notify_exists->forcedelete();
                         }                
                    }
                
                }
            $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
            $updatedPost    = Models::post()->find($commentInfo->id);
            $like_count     = $updatedPost->commentLiked()->count();
            $reaction_label = $updatedPost->getPostUserReaction($commentInfo->id, Auth::user()->id);


            if ($reaction_label) {
                $reaction_type = $reaction_label->reaction_key;
                $reaction_label = trans('common.' . $reaction_label->reaction_key);
            } else {
                $reaction_label = $reaction_text;         
            }

            $reaction_html = $theme->partial('reaction-post', ['post_id' => $updatedPost->id, "likes" => $updatedPost->likes, "post_type" => "forum"]);

            // last active time store in db
            updateUserLastActiveAt();
            
            return response()->json(['status' => '200', 'liked' => $likeEvent, 'message' => 'successfully '.$likeMsg, 'likecount' => $like_count, 'notification_id' => '', "reaction_html" => $reaction_html, 'reactionlabel' => $reaction_label, 'reaction_type' => $reaction_type]);
            }
        }
    }

   /* public function getPostReaction(Request $request)
    {

        $post_id = $request->post_id;
        $post    = Models::post()->find($post_id);

        if ($post->likes) {
            $rection          = $post->likes->groupBy('reaction_id');
            $likeheader       = array();
            $likeduserDetails = array();
            foreach ($rection as $key => $value) {
                $likeheader[] = array("reaction_id" => $key, "count" => $value->count(), "name" => $value[0]->reaction->name);
            }
            usort($likeheader, function ($a, $b) {
                return $b['count'] - $a['count'];
            });
            $likes = $post->likes;
        }
        $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        $html  = $theme->partial('reaction', compact('post', 'likes', 'likeheader'));
        return $html;
        //return response()->json(['status' => '200', 'responseHtml' => $html]);
    }*/

    public function getPostReaction(Request $request)
    {
        $post_id = $request->post_id;
        $post    = Models::post()->find($post_id);
        $next_page_url = "";
        $reaction_list_Array  = [];
        if ($post->likes) {
            $rection          = $post->likes->groupBy('reaction_id');
            $likeheader       = array();
            $likeduserDetails = array();
            $reaction_list_Array['all'] = $post->likes()->paginate(25);
            foreach ($rection as $key => $value) {
                $count = $value->count();
                $likeheader[] = array("reaction_id" => $key, 'count' => $count, 'count_label' => number_format_short($count), "name" => $value[0]->reaction->reaction_key);
                //$reaction_list_Array[$value[0]->reaction->reaction_key] = $post->likes()->where('post_likes.reaction_id', '=', $key)->paginate(10);
                $reaction_list_Array[$value[0]->reaction->reaction_key] = [];
            }
            if ($reaction_list_Array['all']->lastPage() >= 2) {
                $next_page_url = url('forums/posts/get-more-post-reaction?page=2&reaction_id=all&post_id=' . $post_id);
            }

            usort($likeheader, function ($a, $b) {
                return $b['count'] - $a['count'];
            });
            $user_id = Auth::user()->id;

            $likes = $post->likes;
        }
        $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        $html  = $theme->partial('reaction', compact('post', 'likes', 'likeheader', 'reaction_list_Array', 'next_page_url'));
        return $html;
    }

    public function getMorePostReaction(Request $request)
    {
        $post_id     = $request->post_id;
        $reaction_id = $request->reaction_id;
        $post        = Models::post()->find($post_id);
        $response_html = "";
        $next_page_url = "";
        $reaction_list  = $post->likes();

        $page      = ($request->page) ? $request->page : 1;
        
        $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        if ($reaction_id != 'all') {
            $reaction_list = $reaction_list->where('chatter_post_likes.reaction_id', '=', $reaction_id);
        }
        $reaction_list  = $reaction_list->paginate(25);

        $next_page = $page + 1;        

        if ($reaction_list) {
            $response_html = $theme->partial('reaction-users', compact('reaction_list'));
        }   

        if ($reaction_list->lastPage() >= $next_page) {
            $next_page_url = url('forums/posts/get-more-post-reaction?page='. $next_page .'&reaction_id='.$reaction_id.'&post_id=' . $post_id);
            $response_html.= "<a class='reaction_url' data-post-type='chatter' id='reaction_".$reaction_id."' href='".$next_page_url."'>".trans('common.show_more')."</a>";
        }

        return response()->json(['response_html' => $response_html, 'reaction_id' => $reaction_id]);
    }

}
