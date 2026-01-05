<?php
if (!defined('ABSPATH')) exit;

/* =========================================================
   LOGIN REDIRECT
========================================================= */
add_filter('login_redirect', function ($redirect_to, $request, $user) {
    if (!($user instanceof WP_User)) return site_url('/login/');
    if (in_array('administrator', $user->roles)) return site_url('/admin-panel/');
    if (in_array('student', $user->roles)) return site_url('/student-dashboard/');
    return home_url();
}, 10, 3);

add_action('template_redirect', function () {
    if (is_page('login') && is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('administrator', $user->roles)) {
            wp_safe_redirect(site_url('/admin-panel/'));
        } elseif (in_array('student', $user->roles)) {
            wp_safe_redirect(site_url('/student-dashboard/'));
        } else {
            wp_safe_redirect(home_url());
        }
        exit;
    }
});

/* =========================================================
   CUSTOM POST TYPES
========================================================= */
add_action('init', function () {

    register_post_type('course', [
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title','editor','thumbnail'],
        'rewrite' => ['slug'=>'course'],
    ]);

    register_post_type('lesson', [
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title','page-attributes'],
        'rewrite' => ['slug'=>'lesson'],
    ]);
});

/* =========================================================
   VIDEO EMBED
========================================================= */
function studyhub_video_embed($url) {
    if (!$url) return '';
    if ($embed = wp_oembed_get($url)) return $embed;

    return '<video controls width="100%">
        <source src="'.esc_url($url).'">
    </video>';
}

/* =========================================================
   DYNAMIC QUIZ GENERATOR (10 MCQs)
========================================================= */
function studyhub_generate_mcqs($lesson_id) {

    $bank = [
        ['q'=>'Which statement about Python character set is correct?','correct'=>'Python character set includes letters, digits, and special symbols','wrong'=>['Python supports only letters','Digits are not allowed','Symbols are ignored']],
        ['q'=>'What is the purpose of variables in Python?','correct'=>'Variables store data values in memory','wrong'=>['Execute loops','Print output','Define keywords']],
        ['q'=>'Which identifier rule is valid in Python?','correct'=>'Can start with letter or underscore','wrong'=>['Start with digit','Contain spaces','Be keyword']],
        ['q'=>'Which data type is supported in Python?','correct'=>'List','wrong'=>['Char array only','Binary string only','None']],
        ['q'=>'Python comments start with?','correct'=>'#','wrong'=>['//','<!--',';']],
        ['q'=>'Which keyword defines a function?','correct'=>'def','wrong'=>['func','define','method']],
        ['q'=>'Which loop is used for sequences?','correct'=>'for','wrong'=>['repeat','do-while','switch']],
        ['q'=>'Which operator is used for power?','correct'=>'**','wrong'=>['^','//','%%']],
        ['q'=>'Which is mutable?','correct'=>'List','wrong'=>['Tuple','String','Integer']],
        ['q'=>'Which prints output?','correct'=>'print()','wrong'=>['echo','cout','printf']]
    ];

    shuffle($bank);
    return array_slice($bank, 0, 10);
}

/* =========================================================
   LESSON PLAYER (VIDEO + QUIZ + MARKS) – REATTEMPT ENABLED
========================================================= */
// ✅ PROCESS QUIZ SUBMISSION FIRST (separate from shortcode)
if (isset($_POST['studyhub_submit_quiz'])) {
    $lesson_id = intval($_POST['lesson_id']);
    $user_id = get_current_user_id();
    
    if ($lesson_id && $user_id) {
        $answers = $_POST['answers'] ?? array();
        $correct = $_POST['correct'] ?? array();
        
        $score = 0;
        $total = count($correct);
        foreach ($answers as $i => $answer) {
            if (isset($correct[$i]) && trim($answer) === trim($correct[$i])) {
                $score++;
            }
        }
        
        // ✅ UPDATE HIGHEST SCORE
        $completed = get_user_meta($user_id, 'studyhub_completed_lessons', true);
        if (!is_array($completed)) $completed = array();
        
        if (!isset($completed[$lesson_id]) || $score > $completed[$lesson_id]) {
            $completed[$lesson_id] = $score;
            update_user_meta($user_id, 'studyhub_completed_lessons', $completed);
        }
        
        // ✅ REDIRECT TO SAME PAGE (forces fresh load)
        wp_redirect(add_query_arg('quiz_result', $score, get_permalink($lesson_id)));
        exit;
    }
}

add_shortcode('lesson_player', function () {
    if (!is_singular('lesson')) return '';
    if (!is_user_logged_in()) return '<p>Please login to access lesson.</p>';

    $lesson_id = get_the_ID();
    $video = get_post_meta($lesson_id, '_lesson_video', true);
    $user_id = get_current_user_id();

    $completed = get_user_meta($user_id, 'studyhub_completed_lessons', true);
    if (!is_array($completed)) $completed = array();

    ob_start();

   // ✅ SHOW JUST-SUBMITTED RESULT - Blue background, white text
if (isset($_GET['quiz_result'])) {
    $latest_score = intval($_GET['quiz_result']);
    $best_score = isset($completed[$lesson_id]) ? $completed[$lesson_id] : 0;
    echo '<div style="padding:20px;background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);border:1px solid #1e40af;margin-bottom:20px;text-align:center;color:#ffffff;">';
    echo '<h3 style="color:#ffffff;margin-bottom:15px;">🎉 Quiz Completed!</h3>';
    echo '<strong style="color:#ffffff;font-size:24px;">Your Score: ' . $latest_score . ' / 10</strong>';
    echo '<br><small style="color:#e0f2fe;">Your best score: ' . $best_score . ' / 10</small>';
    echo '</div>';
}
// ✅ OR SHOW PREVIOUS BEST SCORE - Blue background, white text
elseif (isset($completed[$lesson_id])) {
    echo '<div style="padding:15px;background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);border:1px solid #1d4ed8;margin-bottom:20px;color:#ffffff;">';
    echo '<strong style="color:#ffffff;">Best Score:</strong> ' . $completed[$lesson_id] . ' / 10<br>';
    echo '<em style="color:#e0f2fe;">Re-attempt to improve your score.</em>';
    echo '</div>';
}


    // Video
    if ($video) {
        echo '<div style="margin-bottom:30px">' . studyhub_video_embed($video) . '</div>';
    }

    // ALWAYS SHOW QUIZ
    $questions = studyhub_generate_mcqs($lesson_id);
    echo '<form method="post">';
    echo '<h3>Lesson Quiz</h3>';
    echo '<input type="hidden" name="lesson_id" value="' . $lesson_id . '">';

    foreach ($questions as $i => $q) {
        $options = array_merge(array($q['correct']), $q['wrong']);
        shuffle($options);

        echo '<div style="margin-bottom:20px">';
        echo '<strong>Q' . ($i+1) . '. ' . esc_html($q['q']) . '</strong>';

        foreach ($options as $opt) {
            echo '<label style="display:block;margin:6px 0">';
            echo '<input type="radio" name="answers[' . $i . ']" value="' . esc_attr($opt) . '" required> ' . esc_html($opt);
            echo '</label>';
        }
        echo '<input type="hidden" name="correct[' . $i . ']" value="' . esc_attr($q['correct']) . '">';
        echo '</div>';
    }

    echo '<button type="submit" name="studyhub_submit_quiz">Submit Quiz</button>';
    echo '</form>';

    return ob_get_clean();
});



/* =========================================================
   QUIZ SUBMISSION + SAVE PROGRESS (SINGLE ATTEMPT)
========================================================= */
add_action('init', function () {

    if (!isset($_POST['studyhub_submit_quiz']) || !is_user_logged_in()) return;

    $lesson_id = intval($_POST['lesson_id']);
    $user_id   = get_current_user_id();

    // 🔒 BLOCK RE-ATTEMPTS (SERVER-SIDE SAFETY)
    $completed = get_user_meta($user_id,'studyhub_completed_lessons',true);
    if (is_array($completed) && isset($completed[$lesson_id])) {
        wp_safe_redirect(get_permalink($lesson_id));
        exit;
    }

    $score = 0;
    foreach ($_POST['answers'] as $i => $ans) {
        if ($ans === $_POST['correct'][$i]) $score++;
    }

    if (!is_array($completed)) $completed = [];

    $completed[$lesson_id] = $score;
    update_user_meta($user_id,'studyhub_completed_lessons',$completed);

    wp_safe_redirect(get_permalink($lesson_id));
    exit;
});


/* =========================================================
   FORCE LESSON RENDER
========================================================= */
add_filter('the_content', function ($content) {
    if (!is_singular('lesson')) return $content;
    return do_shortcode('[lesson_player]');
}, 99);

/* =========================================================
   COURSE LESSONS (LOCKED FLOW)
========================================================= */
add_shortcode('course_lessons', function () {

    if (!is_singular('course')) return '';

    $course_id = get_the_ID();
    $user_id = get_current_user_id();

    $completed = get_user_meta($user_id,'studyhub_completed_lessons',true);
    if (!is_array($completed)) $completed = [];

    $lessons = get_posts([
        'post_type'=>'lesson',
        'numberposts'=>-1,
        'orderby'=>'menu_order',
        'order'=>'ASC',
        'meta_query'=>[['key'=>'_lesson_course','value'=>$course_id]]
    ]);

    ob_start();
    echo '<h3>Course Lessons</h3><ul>';

    foreach ($lessons as $index => $lesson) {

        $locked = ($index > 0 && !isset($completed[$lessons[$index-1]->ID]));

        echo '<li style="margin:10px 0">';
        if ($locked) {
            echo '🔒 '.esc_html($lesson->post_title);
        } else {
            echo '<a href="'.get_permalink($lesson->ID).'">'.esc_html($lesson->post_title).'</a>';
        }

        if (isset($completed[$lesson->ID])) {
            echo ' <span style="color:blue">✔ '.$completed[$lesson->ID].'/10</span>';
        }
        echo '</li>';
    }

    echo '</ul>';
    return ob_get_clean();
});

/* =========================================================
   STUDENT COURSE PROGRESS
========================================================= */
add_shortcode('student_course_progress', function () {

    if (!is_user_logged_in()) return '';

    $user_id = get_current_user_id();
    $completed = get_user_meta($user_id,'studyhub_completed_lessons',true);
    if (!is_array($completed)) $completed = [];

    $courses = get_posts(['post_type'=>'course','numberposts'=>-1]);

    ob_start();
    echo '<h3>Your Course Progress</h3>';

    foreach ($courses as $course) {

        $lessons = get_posts([
            'post_type'=>'lesson',
            'numberposts'=>-1,
            'meta_query'=>[['key'=>'_lesson_course','value'=>$course->ID]]
        ]);

        if (!$lessons) continue;

        $done = 0;
        foreach ($lessons as $l) {
            if (isset($completed[$l->ID])) $done++;
        }

        $percent = round(($done/count($lessons))*100);

        echo '<div style="margin-bottom:15px">';
        echo '<strong>'.$course->post_title.'</strong>';
        echo '<div>'.$done.' / '.count($lessons).' lessons completed</div>';
        echo '<div style="background:#eee;height:8px;margin-top:5px">';
        echo '<div style="width:'.$percent.'%;background:#4caf50;height:8px"></div>';
        echo '</div></div>';
    }

    return ob_get_clean();
});
/* =========================================================
   HARD BLOCK PAID LESSONS (NO HTML / CSS / MERGING)
========================================================= */
add_action('pre_get_posts', function ($query) {

    if (is_admin() || !$query->is_main_query()) return;
    if (!is_user_logged_in()) return;

    if (!is_singular('lesson')) return;

    // Allow admins
    if (current_user_can('manage_options')) return;

    $lesson_id = get_queried_object_id();
    if (!$lesson_id) return;

    $is_paid = get_post_meta($lesson_id, '_is_paid_quiz', true);

    if ($is_paid == '1') {
        $query->set_404();
        status_header(404);
        nocache_headers();
    }
});



// 🚫 BLOCK PAID COURSES + SIDEBAR (USING _course_type)
add_action('template_redirect', function() {
    if (is_admin() || !is_user_logged_in()) return;
    
    if (is_singular('course')) {
        global $post;
        $course_type = get_post_meta($post->ID, '_course_type', true);
        if ($course_type !== 'free') {
            wp_redirect(home_url('/courses/'));
            exit;
        }
    }
    
    if (is_singular('lesson')) {
        global $post;
        $course_id = get_post_meta($post->ID, '_lesson_course', true);
        $course_type = get_post_meta($course_id, '_course_type', true);
        if ($course_type !== 'free') {
            wp_redirect(home_url('/courses/'));
            exit;
        }
    }
});

add_filter('the_content', function($content) {
    if (is_admin() || !is_user_logged_in() || !is_singular(array('course', 'lesson'))) return $content;
    
    global $post;
    static $processed = false;
    if ($processed) return $content;
    $processed = true;
    
    $current_course_id = ($post->post_type == 'lesson') ? 
        (int) get_post_meta($post->ID, '_lesson_course', true) : $post->ID;
    $current_lesson_id = ($post->post_type == 'lesson') ? $post->ID : 0;
    
    ob_start(); ?>
    <div class="learning-layout">
        <aside class="learning-sidebar">
            <h3>Courses</h3>
            <?php 
            $all_courses = get_posts(array(
                'post_type' => 'course',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ));
            
            foreach ($all_courses as $course) {
                $course_type = get_post_meta($course->ID, '_course_type', true);
                
                // ✅ HIDE NON-FREE COURSES (matches your dashboard logic)
                if ($course_type !== 'free') continue;
                
                $is_active = $course->ID == $current_course_id;
                echo '<a class="course-link', $is_active ? ' active' : '', '" href="', get_permalink($course->ID), '">';
                echo esc_html($course->post_title);
                echo '</a>';
                
                if ($is_active) {
                    $lessons = get_posts(array(
                        'post_type' => 'lesson',
                        'meta_key' => '_lesson_course',
                        'meta_value' => $course->ID,
                        'orderby' => 'menu_order',
                        'order' => 'ASC'
                    ));
                    if ($lessons) {
                        echo '<ul>';
                        foreach ($lessons as $lesson) {
                            $is_lesson_active = $lesson->ID == $current_lesson_id;
                            echo '<li><a class="lesson-link', $is_lesson_active ? ' active' : '', '" href="', get_permalink($lesson->ID), '">';
                            echo esc_html($lesson->post_title);
                            echo '</a></li>';
                        }
                        echo '</ul>';
                    }
                }
            }
            ?>
        </aside>
        <main class="learning-main"><?php echo $content; ?></main>
    </div>
    <?php 
    return ob_get_clean();
}, 15);

add_action('wp_footer', function() {
    if (!is_singular('lesson') || !is_user_logged_in()) return;
    
    global $post;
    $course_id = get_post_meta($post->ID, '_lesson_course', true);
    $course_type = get_post_meta($course_id, '_course_type', true);
    
    // ✅ BLOCK NON-FREE LESSONS
    if ($course_type !== 'free') return; ?>
    
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var entry = document.querySelector(".entry-content.alignfull.wp-block-post-content");
        if (entry && !entry.querySelector(".learning-layout")) {
            <?php 
            $free_courses = array();
            foreach (get_posts(array('post_type' => 'course', 'posts_per_page' => -1)) as $course) {
                if (get_post_meta($course->ID, '_course_type', true) === 'free') {
                    $free_courses[] = array(
                        'id' => $course->ID,
                        'title' => $course->post_title,
                        'permalink' => get_permalink($course->ID)
                    );
                }
            }
            ?>
            var courses = <?php echo json_encode($free_courses); ?>;
            var sidebar = '<aside class="learning-sidebar"><h3>Courses</h3>';
            for (var i = 0; i < courses.length; i++) {
                var course = courses[i];
                var active = course.id == <?php echo $course_id; ?>;
                sidebar += '<a class="course-link' + (active ? ' active' : '') + '" href="' + course.permalink + '">' + course.title + '</a>';
            }
            sidebar += '</aside>';
            
            var layout = document.createElement("div");
            layout.className = "learning-layout";
            layout.innerHTML = sidebar + '<main class="learning-main"></main>';
            
            var content = entry.innerHTML;
            layout.querySelector(".learning-main").innerHTML = content;
            entry.innerHTML = "";
            entry.appendChild(layout);
            entry.classList.remove("alignfull", "is-layout-constrained", "wp-block-post-content-is-layout-constrained");
            entry.style.cssText = "display: flex; min-height: 600px;";
        }
    });
    </script>
    <?php
});


/* =========================================================
   QUIZ STEP-BY-STEP (FRONTEND ONLY – SAFE ADDON)
========================================================= */
add_action('wp_footer', function () {
    if (!is_singular('lesson') || !is_user_logged_in()) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        const form = document.querySelector('form button[name="studyhub_submit_quiz"]')?.closest('form');
        if (!form) return;

        const questions = form.querySelectorAll('div[style*="margin-bottom:20px"]');
        if (!questions.length) return;

        let current = 0;

        // Hide all questions except first
        questions.forEach((q, i) => {
            q.style.display = i === 0 ? 'block' : 'none';
        });

        // Create navigation
        const nav = document.createElement('div');
        nav.style.marginTop = '20px';

        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.textContent = 'Next';
        nextBtn.style.marginRight = '10px';

        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.textContent = 'Previous';
        prevBtn.style.display = 'none';

        const submitBtn = form.querySelector('button[name="studyhub_submit_quiz"]');
        submitBtn.style.display = 'none';

        nav.appendChild(prevBtn);
        nav.appendChild(nextBtn);
        form.appendChild(nav);

        function updateView() {
            questions.forEach((q, i) => {
                q.style.display = i === current ? 'block' : 'none';
            });

            prevBtn.style.display = current === 0 ? 'none' : 'inline-block';
            nextBtn.style.display = current === questions.length - 1 ? 'none' : 'inline-block';
            submitBtn.style.display = current === questions.length - 1 ? 'inline-block' : 'none';
        }

        nextBtn.addEventListener('click', () => {
            const checked = questions[current].querySelector('input[type="radio"]:checked');
            if (!checked) {
                alert('Please select an answer before continuing.');
                return;
            }
            current++;
            updateView();
        });

        prevBtn.addEventListener('click', () => {
            current--;
            updateView();
        });

        updateView();
    });
    </script>
    <?php
});

/* =========================================================
  RUN ONCE FOR ADMINS
========================================================= */
add_action('admin_post_studyhub_add_lesson', function() {
    if (!isset($_POST['studyhub_nonce']) || !wp_verify_nonce($_POST['studyhub_nonce'], 'studyhub_add_lesson_action')) {
        wp_die('Nonce verification failed');
    }
    if (!current_user_can('administrator')) wp_die('Unauthorized');

    $lesson_id   = intval($_POST['lesson_id'] ?? 0);
    $course_id   = intval($_POST['course_id']);
    $title       = sanitize_text_field($_POST['lesson_title']);
    $video       = esc_url_raw($_POST['lesson_video']);

    // Create/update lesson
    $lesson_data = [
        'ID'         => $lesson_id,
        'post_type'  => 'lesson',
        'post_title' => $title,
        'post_status'=> 'publish',
        'menu_order' => 0
    ];

    $lesson_id = wp_insert_post($lesson_data);

    // Save video meta
    update_post_meta($lesson_id, '_lesson_video', $video);
    update_post_meta($lesson_id, '_lesson_course', $course_id);

    // Force quiz generation if not exists
    if ($video && !get_post_meta($lesson_id,'lesson_quiz_id',true)) {
        $quiz_id = wp_insert_post([
            'post_type' => 'quiz',
            'post_title'=> 'Quiz – '.get_the_title($lesson_id),
            'post_status'=>'publish'
        ]);

        $questions = [
            [
                'question'=>'Did you watch the lesson video?',
                'options'=>['Yes','No'],
                'correct'=>'Yes',
                'marks'=>10
            ]
        ];

        update_post_meta($quiz_id,'quiz_questions',$questions);
        update_post_meta($quiz_id,'pass_marks',10);
        update_post_meta($lesson_id,'lesson_quiz_id',$quiz_id);
    }

    wp_safe_redirect(add_query_arg(['tab'=>'add_course','edit'=>$course_id], site_url('/admin-panel/')));
    exit;
});





















// =============================
// 1️⃣ Ensure /enroll/ page exists
// =============================
add_action('after_setup_theme', function() {
    if (!get_page_by_path('enroll')) {
        wp_insert_post([
            'post_title'   => 'Enroll',
            'post_name'    => 'enroll',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[enroll_course]'
        ]);
    }
});

// =============================
// 2️⃣ Shortcode to handle enrollment
// =============================
add_shortcode('enroll_course', function() {

    // Get course ID dynamically from query param
    $course_id = intval($_GET['course'] ?? 0);
    if (!$course_id) return '<p>No course selected.</p>';

    $course_title = get_the_title($course_id) ?: 'Course';
    $course_url   = get_permalink($course_id) ?: '/courses/';

    // Fetch price dynamically
    $course_price_raw = get_post_meta($course_id, '_course_price', true);
    $course_price = $course_price_raw !== '' 
        ? '₹' . (floatval($course_price_raw) == intval($course_price_raw) 
            ? intval($course_price_raw) 
            : number_format(floatval($course_price_raw), 2)) 
        : '₹999';

    // Handle UPI submission
    if (!empty($_POST['submit_upi']) && !empty($_POST['upi_txn']) && is_user_logged_in()) {
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'paid_courses', array_unique(array_merge(
            (array) get_user_meta($user_id, 'paid_courses', true),
            [$course_id]
        )));
        update_user_meta($user_id, 'upi_payment_' . $course_id, [
            'txn'  => sanitize_text_field($_POST['upi_txn']),
            'time' => current_time('mysql')
        ]);

        // ✅ Redirect to the course itself after payment
        wp_safe_redirect($course_url);
        exit;
    }

    // Output HTML dynamically
    ob_start(); ?>
    <div style="max-width:500px;margin:40px auto;padding:30px;border-radius:20px;box-shadow:0 15px 35px rgba(37,99,235,0.12);background:#f8fafc;">
        <?php if (!is_user_logged_in()): ?>
            <h2 style="color:#1d4ed8;text-align:center;">⚠️ Please Login First</h2>
            <p style="text-align:center;">You need to be logged in to enroll in this course.</p>
            <div style="text-align:center;">
                <a href="<?php echo wp_login_url($_SERVER['REQUEST_URI']); ?>" style="padding:12px 24px;background:#2563eb;color:#fff;border-radius:10px;text-decoration:none;">Login Now</a>
            </div>
        <?php else: ?>
            <h2 style="color:#1d4ed8;text-align:center;margin-bottom:20px;">Enroll via UPI</h2>
            <p><strong>Course:</strong> <span style="color:#2563eb;font-weight:600;"><?php echo esc_html($course_title); ?></span></p>
            <p><strong>Price:</strong> <span style="color:#2563eb;font-weight:800;"><?php echo esc_html($course_price); ?></span></p>
            <p><strong>UPI ID:</strong> <span style="color:#2563eb;font-weight:600;">9067139038@ibl</span></p>
            <img src="https://studyhub.local/wp-content/uploads/2025/12/QR.jpeg" alt="UPI QR" style="display:block;margin:20px auto;max-width:250px;border-radius:12px;box-shadow:0 12px 32px rgba(37,99,235,0.15);">
            <form method="post" style="margin-top:20px;">
                <label>Transaction ID:</label>
                <input type="text" name="upi_txn" required style="width:100%;padding:12px 16px;margin:8px 0;border:2px solid #e5e7eb;border-radius:12px;">
                <button type="submit" name="submit_upi" style="width:100%;padding:14px 20px;background:#2563eb;color:#fff;border:none;border-radius:12px;font-weight:700;">Submit Enrollment Payment</button>
            </form>
        <?php endif; ?>
    </div>
    <style>
        @media(max-width:480px){
            div[style*="max-width:500px"]{margin:20px 15px;padding:20px;}
            img[alt="UPI QR"]{max-width:200px;}
        }
    </style>
    <?php
    return ob_get_clean();
});













































