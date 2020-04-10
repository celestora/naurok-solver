#!/usr/bin/php
<?php declare(strict_types=1);
/**
  * Copyright (c) 2020, fkwa <celestine@vriska.ru>
  *
  * Permission to use, copy, modify, and/or distribute this software for any
  * purpose with or without fee is hereby granted, provided that the above
  * copyright notice and this permission notice appear in all copies.
  *
  * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
  * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
  * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
  * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
  * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
  * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
  * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
  */

# Constants
const JOIN_ENDPOINT   = "https://naurok.com.ua/test/join";
const API_ENDPOINT    = "https://naurok.com.ua/api2/";
const USER_AGENT      = "Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36 OPR/60.0.3255.50747 OPRGX/60.0.3255.50747";
const DEFAULT_HEADERS = [
    "User-Agent: " . USER_AGENT,
];


# CLI Options
$opts = getopt("C:T:", ["vvv", "quiet", "dont-clean", "name:", "mistakes::"]);
define("GAMECODE", (int) $opts["C"], false);
define("GAMENAME", $opts["name"], false);
define("MISTAKES", isset($opts["mistakes"]) ? (int) $opts["mistakes"] : 0, false);
define("QUIET", isset($opts["quiet"]), false);
define("VERBOSE", isset($opts["vvv"]), false);
define("CLEAN", !isset($opts["dont-clean"]), false);
define("SLEEPTIME", isset($opts["T"]) ? (int) $opts["T"] : 1, false);
define("COOKIE_URI", tempnam(__DIR__, "urCookie"), false);


# Util functions
function logMsg(string $level, string $message, bool $verbose = false): void
{
    if(QUIET)
        return;
    else if($verbose && !VERBOSE)
        return;
    
    $date = date(DATE_RFC1123);
    $msg  = "[$date] [$level] — $message";
    
    echo $msg, "\r\n";
}

function abort(string $message): void
{
    exit(logMsg("FATAL", $message));
}

function readHeaders(string $headers): \Traversable
{
    foreach(explode("\r\n", $headers) as $line) {
        $data = explode(": ", $line);
        if(sizeof($data) === 2)
            yield $data[0] => $data[1];
    }
}

function http(string $method, string $url, ?string $payload = NULL, array $headers = [], &$responseHeaders = NULL): ?string
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    if(!is_null($payload))
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_URI); 
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_URI);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(DEFAULT_HEADERS, $headers));
    curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    if($response === false)
        abort("Unexpected request error: " . curl_error($ch) . " №E" . curl_errno($ch));
    
    $headerSize      = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $body            = substr($response, $headerSize);
    
    curl_close($ch);
    return $body;
}

function api(string $method, ?array $args = NULL): object
{
    $put     = !is_null($args);
    $headers = [
        "Origin: https://naurok.com.ua",
        "Referer: https://naurok.com.ua/test/testing",
        "User-Agent: " . USER_AGENT,
    ];
    if($put)
        $headers[] = "Content-Type: application/json;charset=UTF-8";
    
    return json_decode((http($put ? "PUT" : "GET", API_ENDPOINT . str_replace(".", "/", $method), $put ? json_encode($args) : null, $headers)));
}

function csrfToken(string $document): ?string
{
    # PCRE because we don't want to spend all RAM just for some CSRF token.
    preg_match('%<meta name="csrf-token" content="([A-z0-9=\-]++)">%', $document, $matches);
    return $matches[1] ?? NULL;
}

function clean(): void
{
    if(CLEAN)
        unlink(COOKIE_URI);
}


# Functions

function fixSession(object &$session): void
{
    $doc = json_decode(http("GET", "https://naurok.com.ua/api/test/documents/" . $session->settings->document_id));
    if(!$doc)
        abort("Could not fetch answers");
    
    $session->document  = $doc->document;
    $session->questions = $doc->questions;
}

function startTest(int $code, string $name): object
{
    $token = csrfToken(http("GET", JOIN_ENDPOINT));
    if(!$token)
        abort("Could not get token!");
    
    logMsg("DEBUG", "Acquiring CSRF token: done (tok = '$token'", true);
    logMsg("INFO", "Trying to log in as $name to lobby №$code");
    
    $joinParams = http_build_query([
        "_csrf"    => $token,
        "JoinForm" => [
            "gamecode" => $code,
            "name"     => $name,
        ],
    ]);
    http("POST", JOIN_ENDPOINT, $joinParams, [
        "Content-Type: application/x-www-form-urlencoded",
    ], $resps);
    $headers = iterator_to_array(readHeaders($resps));
    if(!isset($headers["location"]))
        abort("Session cannot be initiated. Please check your code.");
    
    $testPage = http("GET", str_replace("http://", "https://", $headers["location"]));
    preg_match('%ng-init="init\([0-9]++ ?,([0-9]++), ?[0-9]++\)"%', $testPage, $matches);
    $sessId   = $matches[1] ?? NULL;
    if(!$sessId)
        abort("Could not get session ID!");
    
    logMsg("DEBUG", "Acquiring session id: done (sess = '$sessId')", true);
    $sess = api("test.sessions/$sessId");
    
    logMsg("INFO", "Successfully logged in, trying to load answers...");
    logMsg("DEBUG", "Session GUID = " . $sess->session->uuid, true);
    fixSession($sess);
    
    return $sess;
}

function submitAnswer(object $session, int $question, array $answers, bool $multiquiz = false): object
{
    if(sizeof($answers) > 1 && !$multiquiz) {
        user_error("Answers array size is incorrect for type 'quiz' (expected 1). Type has been automatically converted to 'multiquiz'.", E_USER_NOTICE);
        $multiquiz = true;
    }
    
    return api("test.responses.answer", [
        "answer"       => $answers,
        "homework"     => true,
        "point"        => (string) 2,
        "homeworkType" => 1,
        "question_id"  => (string) $question,
        "show_answer"  => 0,
        "type"         => ($multiquiz ? "multi" : "") . "quiz",
        "session_id"   => $session->session->id,
    ]);
}


function main(int $argc, array $argv): int
{
    register_shutdown_function("clean");
    
    $sess = startTest(GAMECODE, GAMENAME);
    for($i = 0; $i < sizeof($sess->questions); $i++) {
        $shouldMiss = MISTAKES === 0 ? false : (sizeof($sess->questions) - ($i + 1)) < MISTAKES;
        $question   = $sess->questions[$i];
        $answers    = [];
        foreach($question->options as $option)
            if($option->correct === ($shouldMiss ? "0" : "1"))
                $answers[] = $option->id;
        
        // Will be triggered only if $shouldMiss = true
        if($question->type === "quiz" && sizeof($answers) > 1)
            $answers = [array_rand($answers)]; // Select random incorrect answer
        
        logMsg("INFO", "Attempting to answer question");
        logMsg("INFO", $shouldMiss ? "This question will be answered incorrectly, because of settings" : "Submitting answers...");
        $result = submitAnswer($sess, (int) $question->id, $answers, $question->type === "multiquiz");
        
        logMsg($result->message_scene !== "failed" ? "SUCC" : "ALERT", "Response from server: " . $result->message);
        
        sleep(SLEEPTIME);
    }
    
    logMsg("INFO", "Answered all questions");
    logMsg("INFO", "Closing session");
    api("test.sessions.end/" . $sess->session->id, [
        "payload" => base64_encode(openssl_random_pseudo_bytes(64)),
    ]);
    
    logMsg("SUCC", "Session closed sucessfully: https://naurok.com.ua/test/complete/" . $sess->session->uuid);
    
    return 0;
}

exit(main($_SERVER["argc"], $_SERVER["argv"]));
