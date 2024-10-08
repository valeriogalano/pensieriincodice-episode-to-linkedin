<?php

/**
 * Fetch last episode from podcast feed
 */
function fetch_last_episode(string $feed_url): SimpleXMLElement|false
{
    $feed = simplexml_load_file($feed_url);

    if ($feed === false) {
        error_log('Error fetching feed: ' . print_r(error_get_last(), true));
        exit(1);
    }

    $item = $feed->channel->item[0];

    if ($item === null) {
        error_log('Error fetching last episode: ' . print_r(error_get_last(), true));
        exit(1);
    }

    return $item;
}

/**
 * Publish last episode to LinkedIn as an article
 * @param SimpleXMLElement $last_episode
 * @param string $linkedin_access_token
 * @param string $template
 * @param string $linkedin_person_urn
 */
function publish_to_linkedin(SimpleXMLElement $last_episode, string $linkedin_access_token, string $template, string $linkedin_person_urn): void
{
    if (empty($title = $last_episode->title) || empty($link = $last_episode->link)) {
        error_log('Error fetching last episode: ' . print_r(error_get_last(), true));
        exit(1);
    }

    $content = str_replace(
        ['{title}', '{link}'],
        [escape($title), escape($link)],
        $template
    );

    // log content
    echo "Publishing to LinkedIn as article: $content\n";

    $article = [
        'author' => 'urn:li:person:' . $linkedin_person_urn,
        'lifecycleState' => 'PUBLISHED',
        'specificContent' => [
            'com.linkedin.ugc.ShareContent' => [
                'shareCommentary' => [
                    'text' => $content
                ],
                'shareMediaCategory' => 'ARTICLE',
                'media' => [
                    [
                        'status' => 'READY',
                        'description' => [
                            'text' => 'Article Description'
                        ],
                        'originalUrl' => (string)$link,
                        'title' => [
                            'text' => (string)$title
                        ]
                    ]
                ]
            ]
        ],
        'visibility' => [
            'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
        ]
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.linkedin.com/v2/ugcPosts');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($article));

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $linkedin_access_token,
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Log curl result
    $result = curl_exec($ch);
    echo "Result: $result\n";

    if (curl_errno($ch)) {
        error_log('Error publishing to LinkedIn: ' . curl_error($ch));
        exit(1);
    }

    curl_close($ch);
}

/**
 * @param array|string $string
 * @return array|string|string[]
 */
function escape(array|string $string): string|array
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Add episode link into file
 */
function mark_as_published($last_episode, $file_path): void
{
    if (($link = $last_episode->link) === null) {
        error_log('Error fetching last episode: ' . print_r(error_get_last(), true));
        exit(1);
    }

    echo "Marking as published: $link\n";

    file_put_contents($file_path, "$link\n", FILE_APPEND);
}

/**
 * Search episode link into file
 */
function is_just_published($last_episode, $file_path): bool
{
    if (($link = $last_episode->link) === null) {
        error_log('Error fetching last episode: ' . print_r(error_get_last(), true));
        exit(1);
    }

    $content = file_get_contents($file_path);

    return str_contains($content, $link);
}

$feed_url = getenv('PODCAST_RSS_URL');
$linkedin_access_token = getenv('LINKEDIN_ACCESS_TOKEN');
$linkedin_person_urn = getenv('LINKEDIN_PERSON_URN');
$template = getenv('LINKEDIN_MESSAGE_TEMPLATE');
$file_path = './published_episodes.txt';

if ($last_episode = fetch_last_episode($feed_url)) {
    echo "Last episode fetched successfully: " . $last_episode->link . "\n";
}

if (!is_just_published($last_episode, $file_path)) {
    publish_to_linkedin($last_episode, $linkedin_access_token, $template, $linkedin_person_urn);
    mark_as_published($last_episode, $file_path);
} else {
    echo "Episode already published\n";
}