<?php

namespace DevDojo\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use DB;
use Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Reactions;
use Event as EventListener;

class Post extends Model
{
    use SoftDeletes;
    protected $table = 'chatter_post';
    public $timestamps = true;
    protected $fillable = ['chatter_discussion_id', 'user_id', 'body', 'parent_id', 'markdown'];


    public static function boot() 
    {
        parent::boot();

        static::created(function($discussion) {
            $data['content']      = $discussion->body;
            $data['content_id']   = $discussion->id;
            $data['content_type'] = 'discussion';
            EventListener::fire('content.created', ['content' => $data]);
        });

        static::updated(function($discussion) {
            $data['content']      = $discussion->body;
            $data['content_id']   = $discussion->id;
            $data['content_type'] = 'discussion';
            EventListener::fire('content.updated', ['content' => $data]);
        });

        static::deleted(function($discussion) {
            $data['content']      = $discussion->body;
            $data['content_id']   = $discussion->id;
            $data['content_type'] = 'discussion';
            EventListener::fire('content.deleted', ['content' => $data]);
        });
    }

    public function discussion()
    {
        return $this->belongsTo(Models::className(Discussion::class), 'chatter_discussion_id');
    }

    public function user()
    {
        return $this->belongsTo(config('chatter.user.namespace'));
    }

    public function timeline()
    {
        return $this->belongsTo('App\Timeline')->with('user');
    }

    public function getliked()
    {
        return $this->hasMany(Models::className(ChatterLikes::class), 'chatter_post_id')->where('user_id', Auth::User()->id);
    }

    public function getcountliked()
    {
        return $this->hasMany(Models::className(ChatterLikes::class), 'chatter_post_id');
    }

    public function commentLiked()
    {
        return $this->belongsToMany('App\User', 'chatter_post_likes', 'chatter_post_id', 'user_id')->with('timeline');
    }

    public function comments()
    {
        $commentReplyReportedId  = DB::table('chatter_discussion_reports')->where('comment_id', '!=', NULL)->where('reporter_id', '=', Auth::user()->id)->pluck('comment_id');
        return $this->hasMany(Models::className(Post::class), 'parent_id')->whereNOTIn('id', $commentReplyReportedId);
    }

    public function getPostUserReaction($post_id, $user_id)
    {
        $post_likes = DB::table('chatter_post_likes')->where('chatter_post_id', $post_id)->where('user_id', $user_id)->select('reaction_id')->first();
        if ($post_likes) {
            $result = DB::table('reactions')->where('id', $post_likes->reaction_id)->first();
            return $result;
        }
    }

    public function likes()
    {
        return $this->hasMany(Models::className(ChatterLikes::class), 'chatter_post_id')->with('user','reaction')->latest();
        return $this->belongsToMany('App\User', 'chatter_post_likes', 'chatter_post_id', 'user_id')->with('timeline');
    }

    public function getReactions()
    {
        $result = Reactions::get();
        return $result;
    }

    public static function addReportAbuse($request)
    {
        $id = $request->report_id;
        $discussion_id = $request->discussion_id;
        $reason = $request->reason;
        $reason_content = ($reason == 'other' ? $request->reason_content : NULL);
        $user_id = Auth::user()->id;

        if ($id && $discussion_id && $reason && $user_id) {
            $reported_data = Post::where('id', '=', $id)->first();
            if ($reported_data) {
                $report = DB::table('chatter_discussion_reports')->where('discussion_id', '=', $discussion_id)->where('comment_id', '=', $id)->where('reporter_id', '=', $user_id)->first();
                if (!$report) {
                    $reported = DB::table('chatter_discussion_reports')->insert([
                        'discussion_id' => $discussion_id,
                        'comment_id' => $id, 
                        'reporter_id' => $user_id, 
                        'reason' => $reason, 
                        'reason_content' => $reason_content, 
                        'status' => 'pending', 
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ]);
                } else {
                    $reported = DB::table('chatter_discussion_reports')->where('id', $report->id)->update([
                        'reason' => $reason, 
                        'reason_content' => $reason_content, 
                        'status' => 'pending', 
                        'updated_at' => Carbon::now()
                    ]);
                }
                return $reported;
            }
        }
        return false;
    }
}
