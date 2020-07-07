<?php

/**
 * Helpers.
 */

// Route helper.
$route = function ($accessor, $default = '') {
    return $this->app->config->get('chatter.routes.'.$accessor, $default);
};

// Middleware helper.
$middleware = function ($accessor, $default = []) {
    return $this->app->config->get('chatter.middleware.'.$accessor, $default);
};

// Authentication middleware helper.
$authMiddleware = function ($accessor) use ($middleware) {
    return array_unique(
        array_merge((array) $middleware($accessor), ['auth'])
    );
};

/*
 * Chatter routes.
 */
Route::group([
    'as'         => 'chatter.',
    'prefix'     => $route('home'),
    'middleware' => $middleware('global', 'web'),
    'namespace'  => 'DevDojo\Chatter\Controllers',
], function () use ($route, $middleware, $authMiddleware) {

    // Home view.
    Route::get('/', [
        'as'         => 'home',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('public'),
    ]);

    Route::post('/', [
        'as'         => 'home',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('home'),
    ]);

    Route::get('/past-discussion', [
        'as'         => 'home',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('home'),
    ]);

    /*// My Discussion view.
    Route::get('/my-discussion', [
        'as'         => 'home',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('home'),
    ]);

    // Recent Discussion view.
    Route::get('/recent-discussion', [
        'as'         => 'home',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('public'),
    ]);

    Route::post('/recent-discussion', [
        'as'         => 'home',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('home'),
    ]);*/

    // LIKE & UNLIKE
    Route::get('/vote', [
        'as'         => 'home',
        'uses'       => 'ChatterController@placeLikeOrUnlikeOnForum',
        'middleware' => $middleware('home'),
    ]);

    // Single category view.
    Route::get($route('category').'/{slug}', [
        'as'         => 'category.show',
        'uses'       => 'ChatterController@index',
        'middleware' => $middleware('category.show'),
    ]);

    /*
     * Auth routes.
     */

    // Login view.
    Route::get('login', [
        'as'   => 'login',
        'uses' => 'ChatterController@login',
    ]);

    // Register view.
    Route::get('register', [
        'as'   => 'register',
        'uses' => 'ChatterController@register',
    ]);

    Route::post('/remaining-comment', [
        'as'         => 'remainingcomment',
        'uses'       => 'ChatterDiscussionController@commentPaginationForum',
    ]);

     Route::post('/remaining-reply-comment', [
        'as'         => 'remainingreplycomment',
        'uses'       => 'ChatterDiscussionController@commentReplyPaginationForum',
    ]);

    /*
     * Discussion routes.
     */
    Route::group([
        'as'     => 'discussion.',
        'prefix' => $route('discussion'),
    ], function () use ($middleware, $authMiddleware) {

        // All discussions view.
        Route::get('/', [
            'as'         => 'index',
            'uses'       => 'ChatterDiscussionController@index',
            'middleware' => $middleware('discussion.index'),
        ]);

        // Create discussion view.
        Route::get('create', [
            'as'         => 'create',
            'uses'       => 'ChatterDiscussionController@create',
            'middleware' => $authMiddleware('discussion.create'),
        ]);

        // Store discussion action.
        Route::post('/', [
            'as'         => 'store',
            'uses'       => 'ChatterDiscussionController@store',
            'middleware' => $authMiddleware('discussion.store'),
        ]);

        // Single discussion view.
        Route::get('{category}/{slug}', [
            'as'         => 'showInCategory',
            'uses'       => 'ChatterDiscussionController@show',
            'middleware' => $middleware('discussion.show'),
        ]);

        Route::post('{category}/{slug}', [
            'as'         => 'showInCategory',
            'uses'       => 'ChatterDiscussionController@show',
            'middleware' => $middleware('discussion.show'),
        ]);

        Route::get('{category}/{slug}/{comment_id}', [
            'as'         => 'showInCategory',
            'uses'       => 'ChatterDiscussionController@show',
            'middleware' => $middleware('public'),
        ]);

        Route::post('{category}/{slug}/{comment_id}', [
            'as'         => 'showInCategory',
            'uses'       => 'ChatterDiscussionController@show',
            'middleware' => $middleware('discussion.show'),
        ]);

        // DISCUSSION EDIT.
       /* Route::get('edit/{id}', [
            'uses'       => 'ChatterDiscussionController@edit',
        ]);*/

        // Add user notification to discussion
        Route::post('{category}/{slug}/email', [
            'as'         => 'email',
            'uses'       => 'ChatterDiscussionController@toggleEmailNotification',
        ]);

        /*
         * Specific discussion routes.
         */
        Route::group([
            'prefix' => '{discussion}',
        ], function () use ($middleware, $authMiddleware) {

            // Single discussion view.
            Route::get('/', [
                'as'         => 'show',
                'uses'       => 'ChatterDiscussionController@show',
                'middleware' => $middleware('discussion.show'),
            ]);

            // Edit discussion view.
            Route::get('edit', [
                'as'         => 'edit',
                'uses'       => 'ChatterDiscussionController@edit',
                'middleware' => $authMiddleware('discussion.edit'),
            ]);

            // Update discussion action.
            Route::match(['PUT', 'PATCH'], '/', [
                'as'         => 'update',
                'uses'       => 'ChatterDiscussionController@update',
                'middleware' => $authMiddleware('discussion.update'),
            ]);

            // Destroy discussion action.
            Route::delete('/', [
                'as'         => 'destroy',
                'uses'       => 'ChatterDiscussionController@destroy',
                'middleware' => $authMiddleware('discussion.destroy'),
            ]);
        });
    });

    /*
     * Post routes.
     */
    Route::group([
        'as'     => 'posts.',
        'prefix' => $route('post', 'posts'),
    ], function () use ($middleware, $authMiddleware) {

        // All posts view.
        Route::get('/', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@index',
            'middleware' => $middleware('post.index'),
        ]);

        Route::get('/{slug}', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@edit',
            'middleware' => $middleware('post.index'),
        ]);
        Route::post('/edit', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@editComment',
            'middleware' => $middleware('post.index'),
        ]);
        
        Route::post('/comment-like', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@commentLike',
            'middleware' => $authMiddleware('post.index'),
        ]);

        Route::post('/get-post-reaction', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@getPostReaction',
            'middleware' => $authMiddleware('post.index'),
        ]);

        Route::post('/get-more-post-reaction', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@getMorePostReaction',
            'middleware' => $authMiddleware('post.index'),
        ]);

        Route::get('/delete/{post_id}', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@deleteComment',
            'middleware' => $middleware('post.index'),
        ]);
        Route::get('/report/{post_id}', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@reportDiscussion',
            'middleware' => $middleware('post.index'),
        ]);
        Route::post('/report-chatter', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@reportDiscussion',
            'middleware' => $middleware('post.index'),
        ]);
        Route::post('/report-chatter/comments', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@reportDiscussionComment',
            'middleware' => $middleware('post.index'),
        ]);
        Route::get('/report/{discussion_id}/{post_id}', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@reportDiscussionComment',
            'middleware' => $middleware('post.index'),
        ]);
        Route::post('/{id}', [
            'as'         => 'index',
            'uses'       => 'ChatterPostController@updateDiscussion',
            'middleware' => $middleware('post.index'),
        ]);

        // Create post view.
        Route::get('create', [
            'as'         => 'create',
            'uses'       => 'ChatterPostController@create',
            'middleware' => $authMiddleware('post.create'),
        ]);

        // Store post action.
        Route::post('/', [
            'as'         => 'store',
            'uses'       => 'ChatterPostController@store',
            'middleware' => $authMiddleware('post.store'),
        ]);

        /*
         * Specific post routes.
         */
        Route::group([
            'prefix' => '{post}',
        ], function () use ($middleware, $authMiddleware) {

            // Single post view.
            Route::get('/', [
                'as'         => 'show',
                'uses'       => 'ChatterPostController@show',
                'middleware' => $middleware('post.show'),
            ]);

            // Edit post view.
            Route::get('edit', [
                'as'         => 'edit',
                'uses'       => 'ChatterPostController@edit',
                'middleware' => $authMiddleware('post.edit'),
            ]);

            // Update post action.
            Route::match(['PUT', 'PATCH'], '/', [
                'as'         => 'update',
                'uses'       => 'ChatterPostController@update',
                'middleware' => $authMiddleware('post.update'),
            ]);

            // Destroy post action.
            Route::delete('/', [
                'as'         => 'destroy',
                'uses'       => 'ChatterPostController@destroy',
                'middleware' => $authMiddleware('post.destroy'),
            ]);
          
        });
    });
});

/*
 * Atom routes
 */
Route::get($route('home').'.atom', [
    'as'         => 'chatter.atom',
    'uses'       => 'DevDojo\Chatter\Controllers\ChatterAtomController@index',
    'middleware' => $middleware('home'),
]);
