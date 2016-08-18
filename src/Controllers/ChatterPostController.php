<?php

namespace DevDojo\Chatter\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use DevDojo\Chatter\Models\Post;
use DevDojo\Chatter\Models\Discussion;
use Auth;
use Carbon\Carbon;

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
        if($request->total){
            $total = $request->total;
        }
        if($request->offset){
            $offset = $request->offset;
        }
        $posts = Post::with('user')->orderBy('created_at', 'DESC')->take($total)->offset($offset)->get();
        return response()->json($posts);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        if(config('chatter.security.limit_time_between_posts')){

            if($this->notEnoughTimeBetweenPosts()){
                $minute_copy = (config('chatter.security.time_between_posts') == 1) ? ' minute' : ' minutes';
                $chatter_alert = array(
                    'chatter_alert_type' => 'danger',
                    'chatter_alert' => 'In order to prevent spam, Please allow at least ' . config('chatter.security.time_between_posts') . $minute_copy . ' inbetween submitting content.'
                    );
                return back()->with($chatter_alert);
            }
        }

        $request->request->add(['user_id' => Auth::user()->id]);
        $new_post = Post::create($request->all());
        
        $discussion = Discussion::find($request->chatter_discussion_id);

        if($new_post->id){
            $chatter_alert = array(
                'chatter_alert_type' => 'success',
                'chatter_alert' => 'Response successfully submitted to ' . config('chatter.titles.discussion') . '.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $discussion->slug)->with($chatter_alert);
        } else {
            $chatter_alert = array(
                'chatter_alert_type' => 'danger',
                'chatter_alert' => 'Sorry, there seems to have been a problem submitting your response.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $discussion->slug)->with($chatter_alert);
        }   
    }


    private function notEnoughTimeBetweenPosts(){
        $user = Auth::user();

        $past = Carbon::now()->subMinutes(config('chatter.security.time_between_posts'));

        $last_post = Post::where('user_id', '=', $user->id)->where('created_at', '>=', $past)->first();

        if(isset($last_post)){
            return true;
        }

        return false;
    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $post = Post::find($id);
        if(!Auth::guest() && (Auth::user()->id == $post->user_id)){
            $post->body = $request->body;
            $post->save();

            $discussion = Discussion::find($post->chatter_discussion_id);

            $chatter_alert = array(
                'chatter_alert_type' => 'success',
                'chatter_alert' => 'Successfully updated the ' . config('chatter.titles.discussion') . '.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $discussion->slug)->with($chatter_alert);

        } else {

            $chatter_alert = array(
                'chatter_alert_type' => 'danger',
                'chatter_alert' => 'Nah ah ah... Could not update your response. Make sure you\'re not doing anything shady.'
                );
            return redirect('/' . config('chatter.routes.home'))->with($chatter_alert);

        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $post = Post::find($id);
        if(!Auth::guest() && (Auth::user()->id == $post->user_id)){
            $post->delete();

            $discussion = Discussion::find($post->chatter_discussion_id);

            $chatter_alert = array(
                'chatter_alert_type' => 'success',
                'chatter_alert' => 'Successfully deleted response from the ' . config('chatter.titles.discussion') . '.'
                );
            return redirect('/' . config('chatter.routes.home') . '/' . config('chatter.routes.discussion') . '/' . $discussion->slug)->with($chatter_alert);

        } else {

            $chatter_alert = array(
                'chatter_alert_type' => 'danger',
                'chatter_alert' => 'Nah ah ah... Could not delete the response. Make sure you\'re not doing anything shady.'
                );
            return redirect('/' . config('chatter.routes.home'))->with($chatter_alert);

        }
    }
}