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
use App\Services\Sanitizer;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Media;
use App\Notification;
use App\Page;
use App\Repositories\UserRepository;
use App\Role;
use App\Setting;
use App\Timeline;
use App\Post;
use App\User;
use Carbon\Carbon;
use Flash;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Intervention\Image\Facades\Image;
use Prettus\Repository\Criteria\RequestCriteria;
use Response;
use Facuz\Theme\Facades\Theme;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Route;
use LaravelEmojiOne;
use Storage;

class ChatterDiscussionController extends Controller
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
        $discussions = Models::discussion()->with('user')->with('post')->with('postsCount')->with('category')->orderBy('created_at', 'ASC')->take($total)->offset($offset)->get();
        return response()->json($discussions);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $categories = Models::category()->all();

        return view('chatter::discussion.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, Sanitizer $sanitizer)
    {
        //printArray($request->all()); exit;
        $request->request->add(['body_content' => strip_tags($request->body)]);

        $validator = Validator::make($request->all(), [
            'title'               => 'required|min:5|max:255',
            'body_content'        => 'required|min:10',
            'chatter_category_id' => 'required',
        ]);

       /* Event::fire(new ChatterBeforeNewDiscussion($request, $validator));
        if (function_exists('chatter_before_new_discussion')) {
            chatter_before_new_discussion($request, $validator);
        }*/

        if ($validator->fails()) {
            return response()->json(['status' => '201', 'redirect_url' => url('/'.config('chatter.routes.home')), 'message' => $validator->errors()]);
            //return back()->withErrors($validator)->withInput();
        }

        $user_id = Auth::user()->id;

        if (config('chatter.security.limit_time_between_posts')) {
            if ($this->notEnoughTimeBetweenDiscussion()) {
                $minute_copy = (config('chatter.security.time_between_posts') == 1) ? ' minute' : ' minutes';
                $chatter_alert = [
                    'chatter_alert_type' => 'danger',
                    'chatter_alert'      => 'In order to prevent spam, please allow at least '.config('chatter.security.time_between_posts').$minute_copy.' in between submitting content.',
                    ];


            return response()->json(['status' => '201', 'redirect_url' => url('/'.config('chatter.routes.home')), 'message' => $chatter_alert]);

                //return redirect('/'.config('chatter.routes.home'))->with($chatter_alert)->withInput();
            }
        }

        // *** Let's gaurantee that we always have a generic slug *** //
        /*$slug = str_slug($request->title, '-');

        $discussion_exists = Models::discussion()->where('slug', '=', $slug)->first();
        $incrementer = 1;
        $new_slug = $slug;
        while (isset($discussion_exists->id)) {
            $new_slug = $slug.'-'.$incrementer;
            $discussion_exists = Models::discussion()->where('slug', '=', $new_slug)->first();
            $incrementer += 1;
        }

        if ($slug != $new_slug) {
            $slug = $new_slug;
        }*/

        $new_discussion = [
            'title'               => $request->title,
            'chatter_category_id' => $request->chatter_category_id,
            'user_id'             => $user_id,
            //'slug'                => $slug,
            'color'               => $request->color,
            'status'              => 2,
            ];

        $category = Models::category()->find($request->chatter_category_id);
        if (!isset($category->slug)) {
            $category = Models::category()->first();
        }

        $discussion = Models::discussion()->create($new_discussion);

        $new_post = [
            'chatter_discussion_id' => $discussion->id,
            'user_id'               => $user_id,
            'body'                  => $request->body,
            ];

        if (config('chatter.editor') == 'simplemde') {
           $new_post['markdown'] = 1;
        }

        // add the user to automatically be notified when new posts are submitted
        $discussion->users()->attach($user_id);

        $post = Models::post()->create($new_post);

        if ($post->id) {
            $nudity_count = 0;
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

            /* Event::fire(new ChatterAfterNewDiscussion($request));
            if (function_exists('chatter_after_new_discussion')) {
                chatter_after_new_discussion($request);
            }*/

            $chatter_alert = [
                'chatter_alert_type' => 'success',
                'chatter_alert'      => 'Thank you for your suggestion! We are grateful for your input and look forward to implementing this topic in future discussions.',
            ];

            $activity['id']     =  $discussion->id;
            $activity['type']   = 'forum';
            $activity['text']   = 'activity.created_discussion';
            $activity['action'] = 'create_forum';
            $activity['url']    = url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussion->slug );
            Auth::user()->logUserActivity($activity);

            //Send push notification to followers
            $notification = [
                'discussion_id' => $discussion->id, 
                'notified_by' => Auth::user()->id, 
                'description' => ucwords(Auth::user()->name).' '.trans('activity.created_discussion'), 
                'type' => 'new_discussion'
            ];
            //$followers = Notification::sendNotificationToFollowers($notification);
            return response()->json(['status' => '200', 'redirect_url' => url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug), 'message' => $chatter_alert]);
            //return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = [
                'chatter_alert_type' => 'danger',
                'chatter_alert'      => 'Whoops :( There seems to be a problem creating your '.config('chatter.titles.discussion').'.',
                ];
            return response()->json(['status' => '202', 'redirect_url' => url('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug), 'message' => $chatter_alert]);
            //return redirect('/'.config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$category->slug . '/' . $discussion->slug)->with($chatter_alert);
        }
    }

    private function notEnoughTimeBetweenDiscussion()
    {
        $user = Auth::user();

        $past = Carbon::now()->subMinutes(config('chatter.security.time_between_posts'));

        $last_discussion = Models::discussion()->where('user_id', '=', $user->id)->where('created_at', '>=', $past)->first();

        if (isset($last_discussion)) {
            return true;
        }

        return false;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $category, $slug = null,$dcomment_id=0)
    {
        if (!isset($category) || !isset($slug)) {
            return redirect(config('chatter.routes.home'));
        }

        $discussion = Models::discussion()->where('slug', '=', $slug)->first();
        if (is_null($discussion)) {
            abort(404);
        }

        if($discussion->status!=1) {
             abort(404);
        }

        $forumMedia = DB::table('chatter_media')
            ->join('media', 'media.id', '=', 'chatter_media.media_id')->where('media.type', '=', 'image')->select('media.*')->where('chatter_media.chatter_discussion_id', '=', $discussion->id)->get();
        $discussion->media = $forumMedia;

        $discussion_category = Models::category()->find($discussion->chatter_category_id);
        if ($category != $discussion_category->slug) {
            return redirect(config('chatter.routes.home').'/'.config('chatter.routes.discussion').'/'.$discussion_category->slug.'/'.$discussion->slug);
        }
        
        $reportedId = array();
        if (!Auth::guest()) {
            $reportedId = DB::table('chatter_discussion_reports')->where('reporter_id', '=', Auth::user()->id)->where('comment_id', '!=', NULL)->pluck('comment_id');
            //$replyIds = Models::post()->whereIn('parent_id', $reportedId)->pluck('id')->toArray();
            //$reportedId = array_merge($replyIds, $reportedId);
        }
        $posts = Models::post()->where('parent_id', '=', Null)->with('user')->whereNotIn('id', $reportedId)->where('chatter_discussion_id', '=', $discussion->id)->orderBy('created_at', 'DESC')->offset(1)->paginate(10);
        $discusion_det = Models::post()->where('parent_id', '=', Null)->with('user')->where('chatter_discussion_id', '=', $discussion->id)->orderBy('created_at', 'ASC')->take(1)->offset(0)->get();
        $request->merge(array('discussion_id' => $discussion->id, 'id' => $discusion_det[0]->id, 'page' => 1, 'dcomment_id' => $dcomment_id));
        $commentsHtml = $this->commentPaginationForum($request);
        $chatter_editor = config('chatter.editor');

        //TO GET VOTES OF DISCUSSION
        $discussion_votes = array();
        if (!is_null($discussion) && !Auth::guest() ) {
            $discussion_votes = Models::discussionvote()->where('discussion_id', '=', $discussion->id)->where('user_id', '=', Auth::user()->id)->first();
        }

        // Dynamically register markdown service provider
        \App::register('GrahamCampbell\Markdown\MarkdownServiceProvider');
        $theme = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        
        $title = 'no recod found';

        if ($discussion->title) {
            $title = LaravelEmojiOne::toImage(nl2br(anchor_link_Add(e(getDecodedContent($discussion->title)), 'view')));
        }
        if($discussion->end_date >= \Carbon\Carbon::now()){
            $which_discussion = "Active Discussions";
            $discussion_url = url('forums');
         }else{
            $which_discussion = "Past Discussions";
            $discussion_url = url('forums/past-discussion');
        }
        $theme->breadcrumb()->add(array(
            array(
                'label' => 'Home',
                'url' => '',
            ),
            array(
                'label' => trans('common.discussion_forums'),
                'url' => url('forums')
            ),
            array(
                'label' =>  $which_discussion,
                 'url' => $discussion_url
            ),
            array(
                'label' =>  str_limit($title ,80),
                'url' => url('')
            )
        ));
        $theme->setTitle(setPageTitle($title . ' ' . Setting::get('title_seperator') . ' ' . trans('common.discussion_forum')));

        $theme->setOgImage($discussion->user->avatarImage(450,350));
        if(isset($discusion_det[0]->body)){
            $theme->setMetaDescription(strip_tags($discusion_det[0]->body));
        }
        $theme->setMetaTitle(trans('common.discussion_forums'). " | ".$title);

        return $theme->scope('forum/discussion', compact('discussion', 'posts', 'chatter_editor','discusion_det','discussion_votes','dcomment_id', 'commentsHtml'))->render();

       // return view('chatter::discussion', compact('discussion', 'posts', 'chatter_editor'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
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
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    private function sanitizeContent($content)
    {
        libxml_use_internal_errors(true);
        // create a new DomDocument object
        $doc = new \DOMDocument();

        // load the HTML into the DomDocument object (this would be your source HTML)
        $doc->loadHTML($content);

        $this->removeElementsByTagName('script', $doc);
        $this->removeElementsByTagName('style', $doc);
        $this->removeElementsByTagName('link', $doc);

        // output cleaned html
        return $doc->saveHtml();
    }

    private function removeElementsByTagName($tagName, $document)
    {
        $nodeList = $document->getElementsByTagName($tagName);
        for ($nodeIdx = $nodeList->length; --$nodeIdx >= 0;) {
            $node = $nodeList->item($nodeIdx);
            $node->parentNode->removeChild($node);
        }
    }

    public function toggleEmailNotification($category, $slug = null)
    {
        if (!isset($category) || !isset($slug)) {
            return redirect(config('chatter.routes.home'));
        }

        $discussion = Models::discussion()->where('slug', '=', $slug)->first();

        $user_id = Auth::user()->id;

        // if it already exists, remove it
        if ($discussion->users->contains($user_id)) {
            $discussion->users()->detach($user_id);

            return response()->json(0);
        } else { // otherwise add it
             $discussion->users()->attach($user_id);

            return response()->json(1);
        }
    }

    public function commentPaginationForum(Request $request)
    {
        $theme      = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        $page       = $request->page;
        $reportedId = array();
        $nextpage       = 0;
        $discussion_id  = $request->discussion_id;
        $id             =  $request->id;
        $dcomment_id    = $request->dcomment_id;
        if (!Auth::guest()) {
            $reportedId = DB::table('chatter_discussion_reports')->where('reporter_id', '=', Auth::user()->id)->where('comment_id', '!=', NULL)->pluck('comment_id');
        }
        $discussion     = Models::discussion()->where('id', '=', $request->discussion_id)->first();
        $posts = Models::post()->where('parent_id', '=', Null)->where('id', '!=', $request->id)->with('user')->whereNotIn('id', $reportedId)->where('chatter_discussion_id', '=', $request->discussion_id)->orderBy('created_at', 'DESC')->paginate(10);

        if ($dcomment_id) {
            $parent_comment = Models::post()->where('id', '=', $dcomment_id)->first();
            if ($parent_comment->parent_id) {
                $posts = Models::post()->where('parent_id', '=', Null)->where('id', '!=', $request->id)->with('user')->whereNotIn('id', $reportedId)->where('chatter_discussion_id', '=', $request->discussion_id)->orderByRaw(\DB::raw("FIELD(id, ".$parent_comment->parent_id." ) DESC"))->paginate(10);
            }
        }

        $lastPageNumber = $posts->lastPage();
        if ($page < $lastPageNumber) {
            $nextpage = $page + 1;
        }
        //echo $nextpage; exit;

        $viewComment = isset($dcomment_id) && $dcomment_id ? 'viewComment' : '';
        $commentResponse = '';
        if (count($posts)) {
            foreach($posts as $post) {
                //printArray($post); exit;
                $request->merge(array('comment_id' => $post->id, 'page' => 1));
                $replyResponse = $this->commentReplyPaginationForum($request);
                $commentResponse .= $theme->partial('forum-comment',compact('post','discussion', 'viewComment', 'replyResponse'));
            }
            $commentResponse .= $theme->partial('forum-pagination',compact('nextpage', 'discussion_id', 'id'));
        }
        return  $commentResponse;
    }

    public function commentReplyPaginationForum(Request $request)
    {
        $theme      = Theme::uses(Setting::get('current_theme', 'default'))->layout('default');
        $page       = $request->page;
        $reportedId = array();
        $nextpage       = 0;
        $comment_id      = $request->comment_id;
        $discussion_id  = $request->discussion_id;
        $id             =  $request->id;
        if (!Auth::guest()) {
            $reportedId = DB::table('chatter_discussion_reports')->where('reporter_id', '=', Auth::user()->id)->where('comment_id', '!=', NULL)->pluck('comment_id');
        }

        $discussion = Models::discussion()->where('id', '=', $request->discussion_id)->first();
        $post       = Models::post()->where('id', '=', $comment_id)->first();

        $replys = Models::post()->where('parent_id', '=', $comment_id)->with('user')->whereNotIn('id', $reportedId)->orderBy('created_at', 'DESC')->paginate(10);
        $replyResponse = '';

        $lastPageNumber = $replys->lastPage();
        if ($page < $lastPageNumber) {
            $nextpage = $page + 1;
        }

        if(count($replys)) {
            foreach($replys as $reply) {
                $replyResponse .= $theme->partial('forum-reply',compact('reply','post', 'discussion'));
            }
            $replyResponse .= $theme->partial('forum-pagination',compact('nextpage', 'discussion_id', 'id', 'comment_id'));
        }

        //echo $nextpage; exit;

        return  $replyResponse;
    }
}
