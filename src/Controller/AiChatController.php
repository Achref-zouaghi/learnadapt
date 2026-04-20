<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiChatController extends AbstractController
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are LearnAdapt AI — the intelligent assistant for the LearnAdapt adaptive learning platform. You are friendly, knowledgeable, concise, and professional.

About LearnAdapt:
- An AI-powered adaptive learning platform that personalizes education in real-time.
- Offers 200+ courses across tech, business, design, data science, and more.
- Uses a proprietary AI engine that analyzes learning patterns (pause times, concept revisits, focus patterns) to build unique learner profiles.
- Adjusts difficulty in real-time, suggests review sessions, and predicts challenging topics before learners encounter them.
- Learners master content 3.2× faster than traditional methods.
- 12,000+ active learners from 140+ countries with a 94% completion rate and 4.9★ average rating.

Plans:
- Starter ($29/mo): 5 courses/month, adaptive paths, progress analytics, AI assistant (50 queries/mo), 2 certificates.
- Pro ($79/mo, most popular): Unlimited courses, unlimited AI assistant, unlimited certificates, priority support.
- Teams (custom pricing): Everything in Pro + team analytics, custom course builder, SSO & SCIM, dedicated success manager.
- Annual billing saves 20%. All plans include a 14-day free trial.

Features:
- Adaptive Learning Paths: AI dynamically adjusts curriculum based on performance and pace.
- Real-Time Analytics: Track skills, streaks, milestones with beautiful dashboards.
- AI Study Assistant (you): 24/7 intelligent tutor for explanations, quizzes, and recommendations.
- Team Learning Spaces: Cohorts, course assignments, team progress tracking.
- Verified Certificates: Blockchain-verified, shareable to LinkedIn with one click.
- Multi-Format Content: Video, interactive code editors, quizzes, and reading materials.
- Collaborative Learning: AI-matched study groups and peer projects.
- Pomodoro timers, distraction-free modes, ambient soundscapes for focus.

How it works:
1. Take a 5-minute AI assessment that maps knowledge level and learning style.
2. Receive a fully personalized curriculum (right topics, right order, right depth).
3. As you progress, the AI continuously refines your path based on performance.

Guidelines for your responses:
- Be helpful, clear, and concise. Use short paragraphs.
- Use emoji sparingly for warmth (1-2 per message max).
- If asked about something unrelated to education/LearnAdapt, politely redirect.
- Never make up features that don't exist. If unsure, say so honestly.
- For account-specific questions, direct users to Settings or Support.
- You may help with general study tips, learning strategies, and motivation.
- Format responses with markdown when helpful (bold, bullet points).
PROMPT;

    #[Route('/api/ai-chat', name: 'api_ai_chat', methods: ['POST'])]
    public function chat(Request $request, HttpClientInterface $httpClient): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || empty($data['messages'])) {
            return $this->json(['error' => 'No messages provided'], 400);
        }

        $userMessage = '';
        $msgs = $data['messages'];
        for ($i = count($msgs) - 1; $i >= 0; $i--) {
            if (($msgs[$i]['role'] ?? '') === 'user') {
                $userMessage = $msgs[$i]['content'] ?? '';
                break;
            }
        }

        // Always try smart offline first — it uses real user data
        $user = $this->getUser();
        $smartReply = $this->generateSmartReply($userMessage, $user, $data['messages']);
        if ($smartReply) {
            return $this->json(['reply' => $smartReply]);
        }

        // Try Groq API as enhancement
        $apiKey = $this->getParameter('app.groq_api_key');
        if ($apiKey && $apiKey !== 'your-groq-api-key-here') {
            try {
                $messages = [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ];
                $userMessages = array_slice($data['messages'], -20);
                foreach ($userMessages as $msg) {
                    $role = $msg['role'] === 'user' ? 'user' : 'assistant';
                    $content = mb_substr($msg['content'] ?? '', 0, 2000);
                    $messages[] = ['role' => $role, 'content' => $content];
                }

                $response = $httpClient->request('POST', 'https://api.groq.com/openai/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'llama-3.3-70b-versatile',
                        'messages' => $messages,
                        'max_tokens' => 500,
                        'temperature' => 0.7,
                    ],
                    'timeout' => 30,
                ]);

                $result = $response->toArray();
                $reply = $result['choices'][0]['message']['content'] ?? '';
                if ($reply) {
                    return $this->json(['reply' => $reply]);
                }
            } catch (\Exception $e) {
                // Fall through to offline
            }
        }

        // Final fallback
        return $this->json(['reply' => $this->getGenericFallback($userMessage)]);
    }

    /**
     * Smart offline AI engine — uses real user data from database
     */
    private function generateSmartReply(string $query, $user, array $conversationHistory): ?string
    {
        $q = mb_strtolower(trim($query));
        if (!$q) return null;

        $userId = $user ? $user->getId() : null;

        // ─── GREETINGS ───
        if (preg_match('/^(hi|hello|hey|hola|bonjour|salut|yo|sup|good\s*(morning|afternoon|evening|night)|what\'?s\s*up)/i', $q)) {
            $name = $user ? $user->getFullName() : 'there';
            $hour = (int)date('G');
            $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
            $replies = [
                "$greeting, **$name**! 👋 How can I help you learn today?",
                "Hey **$name**! 😊 Ready to learn something awesome? Ask me anything!",
                "Hello **$name**! I'm your AI study buddy. What would you like to explore today?",
            ];
            return $replies[array_rand($replies)];
        }

        // ─── THANKS ───
        if (preg_match('/^(thanks?|thx|thank\s*you|merci|gracias|ty|appreciate)/i', $q)) {
            return ["You're welcome! 😊 Happy learning!", "Anytime! Keep up the great work! 🚀", "Glad I could help! Feel free to ask more anytime."][array_rand([0,1,2])];
        }

        // ─── MY PROGRESS / STATS ───
        if ($userId && preg_match('/my\s*(progress|stats|statistic|dashboard|xp|points|score|level|streak|performance|grade|result)/i', $q)) {
            return $this->getProgressReport($userId);
        }

        // ─── MY COURSES / WHAT AM I STUDYING ───
        if ($userId && preg_match('/(my\s*(course|class|enrol|learning)|what\s*(am\s*i|i\'?m)\s*(study|learn|tak|enrol)|current\s*course|enrolled)/i', $q)) {
            return $this->getMyCourses($userId);
        }

        // ─── RECOMMEND / SUGGEST COURSES ───
        if (preg_match('/(recommend|suggest|what\s*should\s*i\s*(learn|study|take)|next\s*course|course.*for\s*me|what\s*to\s*(learn|study))/i', $q)) {
            return $this->getRecommendations($userId);
        }

        // ─── SEARCH COURSES ───
        if (preg_match('/(course|class|lesson|tutorial)\s*(about|on|for|in|related)?\s+(.+)/i', $q, $m)) {
            $topic = trim($m[3], '?. ');
            if (strlen($topic) >= 2) {
                return $this->searchCourses($topic);
            }
        }
        if (preg_match('/(find|search|look\s*for|show\s*me|any|are\s*there)\s*(course|class|lesson|tutorial)s?\s*(about|on|for|in|related\s*to)?\s*(.+)/i', $q, $m)) {
            $topic = trim($m[4], '?. ');
            if (strlen($topic) >= 2) {
                return $this->searchCourses($topic);
            }
        }

        // ─── STUDY PLAN ───
        if (preg_match('/(study\s*plan|learning\s*plan|schedule|routine|how\s*(to|should)\s*(study|plan|organiz)|daily\s*plan|weekly\s*plan|study\s*schedul)/i', $q)) {
            return $this->generateStudyPlan($userId);
        }

        // ─── STUDY TIPS ───
        if (preg_match('/(study\s*tip|learn.*tip|how\s*to\s*(study|learn|memoriz|focus|concentrate|retain)|study.*better|improve.*study|study\s*hack|study\s*technique|study\s*method|study\s*strateg)/i', $q)) {
            return $this->getStudyTips();
        }

        // ─── EXAM / TEST PREP ───
        if (preg_match('/(exam|test|final|midterm|assessment|quiz)\s*(prep|tip|help|strateg|advi)/i', $q)) {
            return $this->getExamTips();
        }

        // ─── MOTIVATION ───
        if (preg_match('/(motivat|inspire|discouraged|tired|burnt?\s*out|don\'?t\s*feel\s*like|lazy|procrastinat|unmotivat|give\s*up|quitting|bored|boring)/i', $q)) {
            return $this->getMotivation($userId);
        }

        // ─── EXPLAIN A CONCEPT ───
        if (preg_match('/(explain|what\s*is|what\s*are|define|definition|meaning\s*of|tell\s*me\s*about|how\s*does|how\s*do|what\s*does|describe|concept\s*of)\s+(.+)/i', $q, $m)) {
            $concept = trim($m[2], '?. ');
            if (strlen($concept) >= 2) {
                return $this->explainConcept($concept);
            }
        }

        // ─── QUIZ ME ───
        if (preg_match('/(quiz\s*me|test\s*me|ask\s*me|practice\s*question|flashcard|question.*about|challenge\s*me)/i', $q)) {
            return $this->generateQuiz($q, $userId);
        }

        // ─── POMODORO / FOCUS ───
        if (preg_match('/(pomodoro|focus\s*(timer|technique|method|mode)|time\s*manag|productiv|distract)/i', $q)) {
            return $this->getPomodoroTips($userId);
        }

        // ─── LEADERBOARD ───
        if ($userId && preg_match('/(leaderboard|ranking|rank|top\s*student|top\s*learner|where\s*(do|am)\s*i\s*rank)/i', $q)) {
            return $this->getLeaderboardInfo($userId);
        }

        // ─── EXERCISES ───
        if (preg_match('/(exercise|practice|drill|problem|worksheet|homework)/i', $q)) {
            return $this->getExerciseInfo($userId);
        }

        // ─── BOOKMARKS ───
        if ($userId && preg_match('/(bookmark|saved|favorite|favourit)/i', $q)) {
            return $this->getBookmarks($userId);
        }

        // ─── NOTES ───
        if ($userId && preg_match('/(my\s*note|notes?\s*i|view.*note|show.*note)/i', $q)) {
            return $this->getMyNotes($userId);
        }

        // ─── PLATFORM INFO ───
        if (preg_match('/(about|what\s*is)\s*(learnadapt|this\s*(platform|app|site|website))/i', $q)) {
            return "**LearnAdapt** is an AI-powered adaptive learning platform that personalizes education in real-time! 🧠\n\nHere's what makes us special:\n• **Adaptive AI Engine** — analyzes your learning patterns to customize your path\n• **Smart Progress Tracking** — XP points, streaks, leaderboards\n• **Rich Content** — courses with PDFs, videos, exercises, and quizzes\n• **Community Features** — comments, ratings, forums, and study groups\n• **Personal Tools** — bookmarks, notes, task boards, and Pomodoro timer\n\nLearners master content **3.2× faster** than traditional methods!";
        }

        // ─── PRICING ───
        if (preg_match('/(pric|cost|plan|pay|subscri|free|trial|money|\$|€|£|how\s*much)/i', $q)) {
            return "We offer three plans:\n\n• **Starter** — \$29/mo: 5 courses, adaptive paths, analytics, AI assistant (50 queries/mo), 2 certificates\n• **Pro** — \$79/mo: Unlimited everything + priority support ⭐ Most popular!\n• **Teams** — Custom pricing: Everything in Pro + team analytics, SSO & SCIM\n\nAll plans include a **14-day free trial**. Annual billing saves 20%!";
        }

        // ─── CERTIFICATES ───
        if (preg_match('/(certif|credential|badge|diploma|verify|blockchain|linkedin)/i', $q)) {
            return "Every completed course earns a **blockchain-verified certificate** 🏆\n\n• Instantly shareable to LinkedIn with one click\n• Verifiable by employers via unique certificate ID\n• Pro/Teams plans = unlimited certificates\n• Each certificate shows your score, completion date, and skills earned";
        }

        // ─── SETTINGS / ACCOUNT ───
        if (preg_match('/(setting|account|profile|password|email|chang|updat|delet|language|theme|notif)/i', $q)) {
            return "You can manage your account in **Settings** ⚙️\n\n• **Profile** — Update name, avatar, and bio\n• **Security** — Change password, manage sessions\n• **Preferences** — Language, theme, notifications\n• **Privacy** — Data and visibility controls\n\nGo to your profile icon → Settings to make changes!";
        }

        // ─── FORUM ───
        if (preg_match('/(forum|discussion|communit|group|peer|connect|social)/i', $q)) {
            return "Our **Community Forum** is a great place to learn together! 🤝\n\n• Ask questions and get answers from peers and experts\n• Share knowledge and resources\n• Join topic-specific discussions\n• Collaborate on projects\n\nHead to the **Forum** section from the sidebar to start!";
        }

        // ─── HELP / SUPPORT ───
        if (preg_match('/(help|support|contact|bug|issue|problem|broken|error|not\s*work)/i', $q)) {
            return "I'm here to help! 💪 Here's what I can do:\n\n• 📚 **Course info** — search, recommend, and explore courses\n• 📊 **Your progress** — stats, XP, streaks, rankings\n• 📝 **Study help** — tips, plans, quizzes, concept explanations\n• 🎯 **Motivation** — overcome procrastination and stay on track\n• ⚙️ **Platform guide** — settings, bookmarks, exercises\n\nFor technical issues, contact **support@learnadapt.io** or use the Feedback page.";
        }

        // No specific match — return null to fall through
        return null;
    }

    // ─────────────────────────────────────────────────
    // DATA-DRIVEN RESPONSE GENERATORS
    // ─────────────────────────────────────────────────

    private function getProgressReport(int $userId): string
    {
        $totalCourses = (int)$this->conn()->fetchOne('SELECT COUNT(*) FROM course_progress WHERE user_id = ?', [$userId]);
        $completedCourses = (int)$this->conn()->fetchOne('SELECT COUNT(*) FROM course_progress WHERE user_id = ? AND progress_percent = 100', [$userId]);
        $totalXp = (int)$this->conn()->fetchOne('SELECT COALESCE(SUM(xp_earned), 0) FROM course_progress WHERE user_id = ?', [$userId]);
        $avgProgress = (int)$this->conn()->fetchOne('SELECT COALESCE(AVG(progress_percent), 0) FROM course_progress WHERE user_id = ?', [$userId]);
        $streak = $this->conn()->fetchAssociative('SELECT current_streak, longest_streak FROM user_streaks WHERE user_id = ?', [$userId]);
        $bookmarks = (int)$this->conn()->fetchOne('SELECT COUNT(*) FROM course_bookmarks WHERE user_id = ?', [$userId]);
        $notes = (int)$this->conn()->fetchOne('SELECT COUNT(*) FROM course_notes WHERE user_id = ?', [$userId]);

        $currentStreak = $streak ? (int)$streak['current_streak'] : 0;
        $longestStreak = $streak ? (int)$streak['longest_streak'] : 0;

        $reply = "📊 **Your Learning Dashboard**\n\n";
        $reply .= "• **Courses Started:** $totalCourses\n";
        $reply .= "• **Courses Completed:** $completedCourses\n";
        $reply .= "• **Average Progress:** {$avgProgress}%\n";
        $reply .= "• **Total XP Earned:** ⚡ {$totalXp} XP\n";
        $reply .= "• **Current Streak:** 🔥 {$currentStreak} days (Best: {$longestStreak})\n";
        $reply .= "• **Bookmarks:** 🔖 {$bookmarks} | **Notes:** 📝 {$notes}\n\n";

        if ($totalCourses === 0) {
            $reply .= "Looks like you haven't started any courses yet! Head to **Courses** to begin your learning journey. 🚀";
        } elseif ($completedCourses > 0 && $avgProgress > 70) {
            $reply .= "You're doing amazingly well! 🎉 Keep up the momentum!";
        } elseif ($avgProgress > 40) {
            $reply .= "Good progress! Keep pushing — you're halfway there! 💪";
        } else {
            $reply .= "Every expert was once a beginner. Keep learning daily and you'll see big results! 📈";
        }

        return $reply;
    }

    private function getMyCourses(int $userId): string
    {
        $courses = $this->conn()->fetchAllAssociative(
            'SELECT c.title, c.level, cp.progress_percent, cp.xp_earned
             FROM course_progress cp
             JOIN courses c ON cp.course_id = c.id
             WHERE cp.user_id = ?
             ORDER BY cp.last_accessed DESC LIMIT 10',
            [$userId]
        );

        if (!$courses) {
            return "You haven't started any courses yet! 📚\n\nHead to the **Courses** page to browse and start learning. I can also recommend courses if you tell me what interests you!";
        }

        $reply = "📚 **Your Courses** (recent first)\n\n";
        foreach ($courses as $i => $c) {
            $pct = (int)$c['progress_percent'];
            $bar = $this->makeProgressBar($pct);
            $status = $pct === 100 ? '✅' : '📖';
            $reply .= "$status **{$c['title']}** ({$c['level']})\n   $bar {$pct}% · ⚡{$c['xp_earned']} XP\n\n";
        }

        $inProgress = count(array_filter($courses, fn($c) => (int)$c['progress_percent'] < 100));
        if ($inProgress > 0) {
            $reply .= "You have **{$inProgress} course" . ($inProgress > 1 ? 's' : '') . "** in progress. Keep going! 💪";
        }

        return $reply;
    }

    private function getRecommendations(?int $userId): string
    {
        // Get what the user has already studied for smart recommendations
        $studied = [];
        $studiedLevels = [];
        if ($userId) {
            $rows = $this->conn()->fetchAllAssociative(
                'SELECT c.module_id, c.level FROM course_progress cp JOIN courses c ON cp.course_id = c.id WHERE cp.user_id = ?',
                [$userId]
            );
            foreach ($rows as $r) {
                if ($r['module_id']) $studied[] = (int)$r['module_id'];
                $studiedLevels[] = $r['level'];
            }
        }

        // Suggest courses they haven't started
        $sql = 'SELECT c.title, c.level, m.name as module_name,
                (SELECT COALESCE(AVG(cr.rating),0) FROM course_ratings cr WHERE cr.course_id = c.id) as avg_rating,
                (SELECT COUNT(*) FROM course_progress cp2 WHERE cp2.course_id = c.id) as enrolled
                FROM courses c
                LEFT JOIN modules m ON c.module_id = m.id';
        $params = [];
        if ($userId) {
            $sql .= ' WHERE c.id NOT IN (SELECT course_id FROM course_progress WHERE user_id = ?)';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY enrolled DESC, avg_rating DESC LIMIT 5';

        $courses = $this->conn()->fetchAllAssociative($sql, $params);

        if (!$courses) {
            return "🎉 Wow, you've explored all our courses! You're a true learning champion!\n\nCheck back soon — new courses are added regularly!";
        }

        $reply = "🎯 **Recommended For You**\n\n";
        foreach ($courses as $c) {
            $stars = $c['avg_rating'] > 0 ? ' ⭐' . round((float)$c['avg_rating'], 1) : '';
            $module = $c['module_name'] ? " · 📁 {$c['module_name']}" : '';
            $reply .= "• **{$c['title']}** ({$c['level']}){$module}{$stars}\n";
        }
        $reply .= "\nHead to **Courses** to start any of these! Want recommendations for a specific topic? Just ask!";

        return $reply;
    }

    private function searchCourses(string $topic): string
    {
        $courses = $this->conn()->fetchAllAssociative(
            'SELECT c.title, c.level, c.description, m.name as module_name
             FROM courses c
             LEFT JOIN modules m ON c.module_id = m.id
             WHERE c.title LIKE ? OR c.description LIKE ? OR m.name LIKE ?
             ORDER BY c.title LIMIT 8',
            ["%$topic%", "%$topic%", "%$topic%"]
        );

        if (!$courses) {
            return "I couldn't find courses matching \"**$topic**\" 🔍\n\nTry a different search term, or browse all courses on the **Courses** page. You can also ask me to recommend courses for your level!";
        }

        $reply = "🔍 **Courses matching \"$topic\"**\n\n";
        foreach ($courses as $c) {
            $module = $c['module_name'] ? " (📁 {$c['module_name']})" : '';
            $desc = $c['description'] ? ' — ' . mb_substr($c['description'], 0, 80) . '...' : '';
            $reply .= "• **{$c['title']}** [{$c['level']}]{$module}{$desc}\n";
        }
        $reply .= "\nVisit **Courses** to explore and enroll!";

        return $reply;
    }

    private function generateStudyPlan(?int $userId): string
    {
        $reply = "📅 **Your Personalized Study Plan**\n\n";

        if ($userId) {
            $inProgress = $this->conn()->fetchAllAssociative(
                'SELECT c.title, cp.progress_percent FROM course_progress cp
                 JOIN courses c ON cp.course_id = c.id
                 WHERE cp.user_id = ? AND cp.progress_percent < 100
                 ORDER BY cp.progress_percent DESC LIMIT 3',
                [$userId]
            );

            if ($inProgress) {
                $reply .= "**Priority: Finish what you started!**\n";
                foreach ($inProgress as $c) {
                    $reply .= "• {$c['title']} — {$c['progress_percent']}% done\n";
                }
                $reply .= "\n";
            }
        }

        $reply .= "**Suggested Daily Schedule:**\n\n";
        $reply .= "🌅 **Morning (30 min)** — Review yesterday's notes + 1 new lesson\n";
        $reply .= "☀️ **Afternoon (25 min)** — Practice exercises + quiz\n";
        $reply .= "🌙 **Evening (15 min)** — Quick revision + write 2-3 key takeaways\n\n";
        $reply .= "**Weekly Structure:**\n";
        $reply .= "• Mon–Thu: Learn new material\n";
        $reply .= "• Friday: Practice & exercises\n";
        $reply .= "• Saturday: Review the week\n";
        $reply .= "• Sunday: Light review or rest\n\n";
        $reply .= "💡 **Pro tip:** Use the **Pomodoro timer** (25 min focus + 5 min break) for maximum retention!\n\n";
        $reply .= "Consistency beats intensity — even 20 minutes daily is better than 3-hour cramming sessions! 🚀";

        return $reply;
    }

    private function getStudyTips(): string
    {
        $tipSets = [
            "🧠 **Top Study Techniques**\n\n1. **Active Recall** — After reading, close the book and write down what you remember. This strengthens memory 3× more than re-reading!\n2. **Spaced Repetition** — Review material at expanding intervals (1 day → 3 days → 7 days → 14 days)\n3. **Feynman Technique** — Explain the concept as if teaching a 10-year-old. If you struggle, you haven't truly understood it\n4. **Interleaving** — Mix different topics in one session instead of studying one topic for hours\n5. **Pomodoro Method** — 25 min focus + 5 min break. After 4 rounds, take a 15-30 min break\n\n💡 The best learners don't study longer — they study **smarter**!",
            "📚 **Study Like a Pro**\n\n• **Before studying:** Set a clear goal (\"I'll learn X by the end of this session\")\n• **While studying:** Take notes in your own words, don't just highlight\n• **After studying:** Quiz yourself — retrieval practice is the #1 learning hack\n• **Sleep well** — Your brain consolidates memories during sleep 😴\n• **Stay hydrated** — Even mild dehydration reduces cognitive performance by 25%\n• **Exercise** — A 20-minute walk boosts focus for 2+ hours\n\n🎯 Start with the hardest topic when your energy is highest!",
            "⚡ **Quick Study Hacks**\n\n1. **Teach someone else** — You remember 90% of what you teach vs 10% of what you read\n2. **Use the 80/20 rule** — Focus on the 20% of concepts that cover 80% of the material\n3. **Create mind maps** — Visual connections beat linear notes\n4. **Study in different locations** — Changes in environment improve recall\n5. **Take handwritten notes** — Writing by hand improves understanding vs typing\n6. **Test yourself before studying** — Pre-testing primes your brain for learning\n\nRemember: **Consistency > Intensity** 🔥",
        ];
        return $tipSets[array_rand($tipSets)];
    }

    private function getExamTips(): string
    {
        return "📝 **Exam Preparation Strategy**\n\n**2 weeks before:**\n• Create a topic checklist\n• Identify weak areas and focus there\n• Start doing practice questions\n\n**1 week before:**\n• Do full practice tests under timed conditions\n• Review mistakes — they're your best teachers\n• Make a 1-page cheat sheet per topic (even if you can't use it)\n\n**Night before:**\n• Light review only — no new material!\n• Get 7-8 hours of sleep (crucial for memory)\n• Prepare everything (ID, pencils, water)\n\n**Exam day:**\n• Eat a good breakfast 🍳\n• Read ALL questions first, start with easiest\n• Don't panic if stuck — move on and come back\n\n💡 **Pro tip:** Practice tests are the single best predictor of exam performance!";
    }

    private function getMotivation(?int $userId): string
    {
        $personalTouch = '';
        if ($userId) {
            $xp = (int)$this->conn()->fetchOne('SELECT COALESCE(SUM(xp_earned), 0) FROM course_progress WHERE user_id = ?', [$userId]);
            $completed = (int)$this->conn()->fetchOne('SELECT COUNT(*) FROM course_progress WHERE user_id = ? AND progress_percent = 100', [$userId]);
            if ($xp > 0 || $completed > 0) {
                $personalTouch = "\n\n🌟 **Look at what you've already achieved:**\n• ⚡ {$xp} XP earned\n• ✅ {$completed} course" . ($completed != 1 ? 's' : '') . " completed\n\nThat's real progress! Don't discount it.";
            }
        }

        $quotes = [
            "\"The expert in anything was once a beginner.\" — Helen Hayes",
            "\"It does not matter how slowly you go as long as you do not stop.\" — Confucius",
            "\"The only way to do great work is to love what you do.\" — Steve Jobs",
            "\"A journey of a thousand miles begins with a single step.\" — Lao Tzu",
            "\"Success is not final, failure is not fatal: it is the courage to continue that counts.\" — Churchill",
        ];
        $quote = $quotes[array_rand($quotes)];

        return "💪 **You've got this!**\n\nFeeling unmotivated is completely normal — every learner goes through it. Here's how to push through:\n\n1. **Start tiny** — Just do 5 minutes. Motivation often follows action, not the other way around\n2. **Remember your WHY** — Why did you start learning? That reason still matters\n3. **Break it down** — A whole course feels overwhelming. One lesson at a time!\n4. **Celebrate small wins** — Finished a lesson? That's worth celebrating 🎉\n5. **Don't compare** — Compare with yesterday's you, not with others\n\n$quote{$personalTouch}\n\nEven opening this chat shows you care about your growth. That counts! 🚀";
    }

    private function explainConcept(string $concept): string
    {
        $concepts = [
            'algorithm' => "🧮 **Algorithm**\n\nAn algorithm is a **step-by-step set of instructions** to solve a specific problem — like a recipe for cooking!\n\n**Example:** Finding the largest number in a list:\n1. Assume the first number is the largest\n2. Compare it with the next number\n3. If the next is bigger, it becomes the new largest\n4. Repeat until the end\n\n**Key properties:** Must be finite, definite (clear steps), and produce output.\n\n**Real-world examples:** Google Search rankings, GPS navigation, Netflix recommendations!",
            'variable' => "📦 **Variable**\n\nA variable is like a **labeled box** that stores data in your program.\n\n**Example:**\n• `name = \"Alice\"` — a box labeled 'name' containing text\n• `age = 25` — a box labeled 'age' containing a number\n• `is_student = true` — a box containing true/false\n\nYou can change what's inside the box anytime — that's why it's called a *variable* (it varies)!\n\n**Types:** integers, strings (text), booleans (true/false), arrays (lists), objects (complex data).",
            'function' => "⚡ **Function**\n\nA function is a **reusable block of code** that performs a specific task — like a machine in a factory!\n\n**Analogy:** A coffee machine:\n• **Input:** water + beans\n• **Process:** grind, heat, brew\n• **Output:** coffee ☕\n\n**In code:**\n```\nfunction greet(name):\n    return \"Hello, \" + name + \"!\"\n\ngreet(\"Alice\") → \"Hello, Alice!\"\n```\n\n**Why use functions?** Write once, use many times. Makes code organized and avoids repetition!",
            'loop' => "🔄 **Loop**\n\nA loop **repeats a block of code** multiple times — like rewinding and replaying!\n\n**Types:**\n• **For loop** — repeat a fixed number of times (\"do this 10 times\")\n• **While loop** — repeat while a condition is true (\"keep going until done\")\n\n**Example:** Printing numbers 1 to 5:\n```\nfor i in 1 to 5:\n    print(i)\n```\nOutput: 1, 2, 3, 4, 5\n\n**Real-world:** Playlist on repeat, assembly line, checking email every hour.",
            'array' => "📋 **Array**\n\nAn array is an **ordered list** of items stored together — like numbered lockers in a hallway!\n\n**Example:**\n`fruits = [\"apple\", \"banana\", \"cherry\"]`\n• `fruits[0]` → \"apple\" (first item)\n• `fruits[1]` → \"banana\"\n• `fruits[2]` → \"cherry\"\n\n**Key facts:**\n• Items are numbered starting from 0\n• Can hold any type of data\n• Easy to loop through all items\n\n**Real-world:** Shopping list, student roster, playlist of songs!",
            'database' => "🗄️ **Database**\n\nA database is an **organized collection of data** stored for easy access — like a super-powered filing cabinet!\n\n**Types:**\n• **Relational (SQL)** — Data in tables with rows & columns (like Excel). Examples: MySQL, PostgreSQL\n• **NoSQL** — Flexible formats (documents, key-value). Examples: MongoDB, Redis\n\n**Example:** A 'users' table:\n| id | name | email |\n|-----|--------|-------------------|\n| 1 | Alice | alice@mail.com |\n| 2 | Bob | bob@mail.com |\n\n**CRUD operations:** Create, Read, Update, Delete — the 4 basic actions on data.",
            'api' => "🔌 **API (Application Programming Interface)**\n\nAn API is a **messenger between applications** — like a waiter in a restaurant!\n\n**Analogy:**\n• You (app) → tell the waiter (API) your order\n• Waiter goes to the kitchen (server)\n• Kitchen prepares food (processes request)\n• Waiter brings back your meal (response)\n\n**Example:** Weather app asks a weather API: \"What's the weather in Paris?\" and gets back temperature, humidity, etc.\n\n**REST API** — The most common type. Uses HTTP methods: GET (read), POST (create), PUT (update), DELETE (remove).",
            'css' => "🎨 **CSS (Cascading Style Sheets)**\n\nCSS controls how HTML elements **look** — colors, fonts, spacing, layout. It's the *fashion designer* of the web!\n\n**Example:**\n```css\nh1 {\n  color: blue;\n  font-size: 24px;\n  margin-bottom: 10px;\n}\n```\n\n**Key concepts:**\n• **Selectors** — target elements (class, id, tag)\n• **Box Model** — margin → border → padding → content\n• **Flexbox/Grid** — modern layout systems\n• **Responsive Design** — adapts to different screen sizes\n\nCSS makes the difference between ugly and beautiful websites! 💅",
            'html' => "📄 **HTML (HyperText Markup Language)**\n\nHTML is the **skeleton** of every web page — it defines the structure and content.\n\n**Key tags:**\n• `<h1>` to `<h6>` — headings\n• `<p>` — paragraphs\n• `<a>` — links\n• `<img>` — images\n• `<div>` — containers\n• `<form>` — user input\n\n**Example:**\n```html\n<h1>Hello World</h1>\n<p>This is my first web page!</p>\n<a href=\"/about\">Learn more</a>\n```\n\n**Think of it as:** HTML = structure, CSS = style, JavaScript = behavior.",
            'javascript' => "⚡ **JavaScript**\n\nJavaScript makes web pages **interactive** — it's the brain of the web!\n\n**What it does:**\n• React to clicks and keyboard input\n• Change page content dynamically\n• Send/receive data without reloading\n• Animations and visual effects\n\n**Example:**\n```javascript\ndocument.getElementById('btn')\n  .addEventListener('click', () => {\n    alert('Hello!');\n  });\n```\n\n**Ecosystem:** React, Vue, Node.js, TypeScript, and thousands of libraries.\n\nJS runs in browsers + servers — it's the most widely-used programming language in the world! 🌍",
            'python' => "🐍 **Python**\n\nPython is a **beginner-friendly**, powerful programming language known for clean, readable code.\n\n**Used for:**\n• 🤖 AI & Machine Learning\n• 📊 Data Science & Analytics\n• 🌐 Web Development (Django, Flask)\n• 🔬 Scientific Computing\n• 🤖 Automation & Scripting\n\n**Example:**\n```python\nfor i in range(5):\n    print(f\"Hello #{i+1}\")\n```\n\n**Why Python?** Easy to learn, huge community, massive library ecosystem (NumPy, Pandas, TensorFlow).\n\nPython is the #1 language for beginners and one of the most in-demand by employers! 🚀",
            'oop' => "🏗️ **OOP (Object-Oriented Programming)**\n\nOOP organizes code into **objects** that contain data and behavior — like real-world things!\n\n**4 Pillars:**\n1. **Encapsulation** — Bundle data + methods together, hide internals\n2. **Inheritance** — Child classes inherit from parents (Dog inherits from Animal)\n3. **Polymorphism** — Same method name, different behaviors\n4. **Abstraction** — Hide complexity, show only what's needed\n\n**Example:**\n```\nclass Car:\n  color = \"red\"\n  def drive():\n    print(\"Vroom!\")\n\nmy_car = Car()\nmy_car.drive()  → \"Vroom!\"\n```\n\n**Real-world:** A TV remote is abstraction — you press buttons without knowing the electronics inside!",
            'machine learning' => "🤖 **Machine Learning**\n\nML lets computers **learn patterns from data** without being explicitly programmed!\n\n**Types:**\n• **Supervised** — Learn from labeled examples (spam/not spam)\n• **Unsupervised** — Find hidden patterns (customer segments)\n• **Reinforcement** — Learn by trial and error (game AI)\n\n**Process:**\n1. Collect data\n2. Train a model\n3. Test accuracy\n4. Deploy and predict\n\n**Real-world examples:**\n• Netflix recommendations\n• Voice assistants (Siri, Alexa)\n• Self-driving cars\n• Medical diagnosis\n\n**Popular tools:** Python, TensorFlow, scikit-learn, PyTorch",
            'git' => "📂 **Git**\n\nGit is a **version control system** — it tracks every change to your code, like a time machine! ⏰\n\n**Key commands:**\n• `git init` — Start tracking a project\n• `git add .` — Stage changes\n• `git commit -m \"message\"` — Save a snapshot\n• `git push` — Upload to remote (GitHub)\n• `git pull` — Download latest changes\n• `git branch` — Create parallel versions\n\n**Why Git?**\n• Never lose code\n• Collaborate with teams\n• Undo mistakes easily\n• Track who changed what and when\n\n**GitHub/GitLab** — Online platforms to host and share Git repos.",
            'sql' => "🗃️ **SQL (Structured Query Language)**\n\nSQL lets you **talk to databases** — ask questions and manage data!\n\n**Basic commands:**\n• `SELECT * FROM users` — Get all users\n• `INSERT INTO users (name) VALUES ('Alice')` — Add data\n• `UPDATE users SET name='Bob' WHERE id=1` — Change data\n• `DELETE FROM users WHERE id=1` — Remove data\n\n**Powerful features:**\n• `JOIN` — Combine tables\n• `WHERE` — Filter results\n• `GROUP BY` — Aggregate data\n• `ORDER BY` — Sort results\n\nSQL is essential for any developer — nearly every app uses a database! 💪",
        ];

        $conceptLower = mb_strtolower($concept);

        // Direct match
        if (isset($concepts[$conceptLower])) {
            return $concepts[$conceptLower];
        }

        // Partial match
        foreach ($concepts as $key => $explanation) {
            if (str_contains($conceptLower, $key) || str_contains($key, $conceptLower)) {
                return $explanation;
            }
        }

        // Check if there's a course about it
        $course = $this->conn()->fetchAssociative(
            'SELECT title, description, level FROM courses WHERE title LIKE ? OR description LIKE ? LIMIT 1',
            ["%$concept%", "%$concept%"]
        );

        if ($course) {
            $desc = $course['description'] ? "\n\n{$course['description']}" : '';
            return "I found a course related to **$concept**: **{$course['title']}** ({$course['level']}){$desc}\n\nHead to **Courses** to learn more about this topic!";
        }

        return "🤔 That's a great question about **$concept**!\n\nWhile I don't have a built-in explanation for this specific topic, here's what you can do:\n\n1. Search our **Courses** page for related material\n2. Ask in the **Forum** — our community is very helpful!\n3. Try asking me about related concepts\n\nI can explain programming concepts like algorithms, variables, functions, databases, APIs, HTML, CSS, JavaScript, Python, OOP, Git, SQL, and more!";
    }

    private function generateQuiz(?string $query, ?int $userId): string
    {
        $quizzes = [
            [
                'q' => "**🧠 Quick Quiz!**\n\nWhat does HTML stand for?\n\nA) Hyper Text Making Language\nB) Hyper Text Markup Language\nC) Home Tool Markup Language\nD) Hyperlinks and Text Markup Language",
                'a' => "\n\n✅ **Answer: B) Hyper Text Markup Language**\n\nHTML is the standard markup language for creating web pages. It defines the structure and content using tags like `<h1>`, `<p>`, `<div>`."
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nWhich of these is NOT a programming language?\n\nA) Python\nB) JavaScript\nC) HTML\nD) Java",
                'a' => "\n\n✅ **Answer: C) HTML**\n\nHTML is a *markup language*, not a programming language. It describes structure but can't perform logic, loops, or calculations."
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nWhat does SQL stand for?\n\nA) Strong Question Language\nB) Structured Query Language\nC) Simple Query Language\nD) Standard Question Language",
                'a' => "\n\n✅ **Answer: B) Structured Query Language**\n\nSQL is used to communicate with databases. It can retrieve, insert, update, and delete data."
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nIn CSS, which property changes the text color?\n\nA) text-color\nB) font-color\nC) color\nD) text-style",
                'a' => "\n\n✅ **Answer: C) color**\n\nThe `color` property in CSS sets the text color. Example: `color: blue;` or `color: #3498db;`"
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nWhat is the time complexity of binary search?\n\nA) O(n)\nB) O(n²)\nC) O(log n)\nD) O(1)",
                'a' => "\n\n✅ **Answer: C) O(log n)**\n\nBinary search divides the search space in half each step -- so for 1000 items, it takes only ~10 comparisons!"
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nWhich HTTP method is used to update data?\n\nA) GET\nB) POST\nC) PUT\nD) DELETE",
                'a' => "\n\n✅ **Answer: C) PUT**\n\nGET = read, POST = create, PUT = update, DELETE = remove. These are the four main REST API methods."
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nWhat does 'git commit' do?\n\nA) Uploads code to GitHub\nB) Downloads the latest changes\nC) Saves a snapshot of staged changes\nD) Deletes a branch",
                'a' => "\n\n✅ **Answer: C) Saves a snapshot of staged changes**\n\n`git commit` creates a permanent record of your staged changes. `git push` is what uploads to GitHub!"
            ],
            [
                'q' => "**🧠 Quick Quiz!**\n\nIn Python, what does `len([1, 2, 3])` return?\n\nA) 6\nB) [1, 2, 3]\nC) 3\nD) Error",
                'a' => "\n\n✅ **Answer: C) 3**\n\n`len()` returns the number of items in a list. The list [1, 2, 3] has 3 elements."
            ],
        ];

        $quiz = $quizzes[array_rand($quizzes)];
        return $quiz['q'] . $quiz['a'] . "\n\nWant another question? Just say **\"quiz me\"** again! 🎯";
    }

    private function getPomodoroTips(?int $userId): string
    {
        $pomodoroCount = '';
        if ($userId) {
            $sessions = (int)$this->conn()->fetchOne(
                'SELECT COUNT(*) FROM pomodoro_sessions WHERE user_id = ?', [$userId]
            );
            if ($sessions > 0) {
                $totalMin = (int)$this->conn()->fetchOne(
                    'SELECT COALESCE(SUM(duration), 0) FROM pomodoro_sessions WHERE user_id = ?', [$userId]
                );
                $pomodoroCount = "\n\n📊 **Your Pomodoro Stats:** {$sessions} sessions completed ({$totalMin} total minutes of focused study!)";
            }
        }

        return "🍅 **The Pomodoro Technique**\n\nThe most effective focus method, backed by science!\n\n**How it works:**\n1. Pick one task to focus on\n2. Set a timer for **25 minutes**\n3. Work with ZERO distractions\n4. When the timer rings, take a **5-minute break**\n5. After 4 rounds, take a **15-30 minute break**\n\n**Tips for success:**\n• Put your phone in another room 📱\n• Close all unrelated browser tabs\n• Tell others not to disturb you\n• Use the LearnAdapt **Pomodoro timer** (built into the platform!)\n\n**Why it works:** Your brain can sustain deep focus for ~25 minutes. Regular breaks prevent burnout and improve retention.{$pomodoroCount}";
    }

    private function getLeaderboardInfo(int $userId): string
    {
        $totalXp = (int)$this->conn()->fetchOne(
            'SELECT COALESCE(SUM(xp_earned), 0) FROM course_progress WHERE user_id = ?', [$userId]
        );

        $rank = (int)$this->conn()->fetchOne(
            'SELECT COUNT(DISTINCT user_id) + 1 FROM course_progress
             WHERE user_id != ? GROUP BY user_id HAVING SUM(xp_earned) > ?',
            [$userId, $totalXp]
        );
        if (!$rank) $rank = 1;

        $top5 = $this->conn()->fetchAllAssociative(
            'SELECT u.full_name, SUM(cp.xp_earned) as total_xp
             FROM course_progress cp JOIN users u ON cp.user_id = u.id
             GROUP BY cp.user_id, u.full_name
             HAVING total_xp > 0
             ORDER BY total_xp DESC LIMIT 5'
        );

        $reply = "🏆 **Leaderboard**\n\n";
        $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
        foreach ($top5 as $i => $row) {
            $medal = $medals[$i] ?? ($i + 1) . '.';
            $reply .= "$medal **{$row['full_name']}** — ⚡ {$row['total_xp']} XP\n";
        }

        $reply .= "\n**Your rank:** #{$rank} with ⚡ {$totalXp} XP\n\n";

        if ($rank <= 3) {
            $reply .= "You're in the **top 3**! Amazing work! 🎉🔥";
        } elseif ($rank <= 10) {
            $reply .= "You're in the **top 10** — keep pushing to reach the podium! 💪";
        } else {
            $reply .= "Complete more courses to earn XP and climb the ranks! Every lesson counts! 📈";
        }

        return $reply;
    }

    private function getExerciseInfo(?int $userId): string
    {
        $exerciseCount = (int)$this->conn()->fetchOne('SELECT COUNT(*) FROM exercice');
        $modules = $this->conn()->fetchAllAssociative(
            'SELECT m.name, COUNT(e.id) as cnt FROM modules m
             JOIN exercice e ON e.module = m.name
             GROUP BY m.name ORDER BY cnt DESC LIMIT 5'
        );

        $reply = "📝 **Exercises & Practice**\n\nWe have **{$exerciseCount} exercises** across multiple subjects!\n\n";
        if ($modules) {
            $reply .= "**Top categories:**\n";
            foreach ($modules as $m) {
                $reply .= "• {$m['name']} — {$m['cnt']} exercises\n";
            }
            $reply .= "\n";
        }

        $reply .= "Each exercise includes:\n• 📄 Exercise PDF (problem set)\n• ✅ Correction PDF (solutions)\n• 📊 Difficulty rating\n\nVisit the **Exercises** page to start practicing!";

        return $reply;
    }

    private function getBookmarks(int $userId): string
    {
        $bookmarks = $this->conn()->fetchAllAssociative(
            'SELECT c.title, c.level FROM course_bookmarks cb
             JOIN courses c ON cb.course_id = c.id
             WHERE cb.user_id = ?
             ORDER BY cb.created_at DESC LIMIT 10',
            [$userId]
        );

        if (!$bookmarks) {
            return "🔖 You haven't bookmarked any courses yet!\n\nWhen viewing a course, click the **Bookmark** button to save it for later. It's a great way to create your personal reading list!";
        }

        $reply = "🔖 **Your Bookmarked Courses**\n\n";
        foreach ($bookmarks as $b) {
            $reply .= "• **{$b['title']}** [{$b['level']}]\n";
        }
        $reply .= "\nVisit any bookmarked course from the **Courses** page!";

        return $reply;
    }

    private function getMyNotes(int $userId): string
    {
        $notes = $this->conn()->fetchAllAssociative(
            'SELECT cn.content, cn.created_at, c.title FROM course_notes cn
             JOIN courses c ON cn.course_id = c.id
             WHERE cn.user_id = ?
             ORDER BY cn.created_at DESC LIMIT 5',
            [$userId]
        );

        if (!$notes) {
            return "📝 You haven't written any notes yet!\n\nWhen viewing a course, use the **Notes** panel in the sidebar to jot down key points. Notes are private and visible only to you!";
        }

        $reply = "📝 **Your Recent Notes**\n\n";
        foreach ($notes as $n) {
            $content = mb_substr($n['content'], 0, 100);
            $reply .= "**{$n['title']}** — {$n['created_at']}\n> {$content}" . (mb_strlen($n['content']) > 100 ? '...' : '') . "\n\n";
        }
        $reply .= "View all your notes inside each course page!";

        return $reply;
    }

    private function makeProgressBar(int $pct): string
    {
        $filled = (int)round($pct / 10);
        return str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
    }

    private function getGenericFallback(string $query): string
    {
        return "I'd love to help with that! 😊\n\nHere's what I can assist you with:\n\n• 📊 **\"My progress\"** — See your stats, XP, and streak\n• 📚 **\"My courses\"** — View your current courses\n• 🎯 **\"Recommend courses\"** — Get personalized suggestions\n• 🔍 **\"Courses about Python\"** — Search for specific topics\n• 📅 **\"Study plan\"** — Get a personalized schedule\n• 🧠 **\"Study tips\"** — Learn effective techniques\n• ❓ **\"Explain algorithms\"** — Understand any concept\n• 📝 **\"Quiz me\"** — Test your knowledge\n• 🍅 **\"Pomodoro\"** — Focus techniques\n• 🏆 **\"Leaderboard\"** — See rankings\n• 🔖 **\"My bookmarks\"** — View saved courses\n• 💪 **\"Motivate me\"** — Get inspired!\n\nJust type naturally — I understand plain English!";
    }
}
