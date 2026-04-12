<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiChatController extends AbstractController
{
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

        $apiKey = $this->getParameter('app.groq_api_key');
        if (!$apiKey || $apiKey === 'your-groq-api-key-here') {
            return $this->json(['error' => 'no_key'], 503);
        }

        // Build messages array with system prompt
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ];

        $userMessages = array_slice($data['messages'], -20);
        foreach ($userMessages as $msg) {
            $role = $msg['role'] === 'user' ? 'user' : 'assistant';
            $content = mb_substr($msg['content'] ?? '', 0, 2000);
            $messages[] = ['role' => $role, 'content' => $content];
        }

        try {
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

            return $this->json(['reply' => $reply]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $status = 502;
            $detail = 'AI service unavailable';

            if (str_contains($msg, '429')) {
                $detail = 'Rate limited — please wait a moment and try again';
                $status = 429;
            } elseif (str_contains($msg, '401')) {
                $detail = 'Invalid API key — get a free key at https://console.groq.com/keys';
                $status = 401;
            }

            return $this->json([
                'error' => $detail,
                'fallback' => true,
            ], $status);
        }
    }
}
