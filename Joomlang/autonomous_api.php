<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'php_langchain/AutonomousAgentSystem.php';

use LangChainPHP\AutonomousAgentSystem;

session_start();

// Initialize the autonomous agent system
$config = [
    'llm' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: 'your-openai-api-key',
        'model' => 'gpt-3.5-turbo'
    ]
];

// Store the agent system in session to maintain state
if (!isset($_SESSION['autonomous_system'])) {
    $_SESSION['autonomous_system'] = new AutonomousAgentSystem($config);
}

$autonomousSystem = $_SESSION['autonomous_system'];
$agentSystem = $autonomousSystem; // Alias for compatibility

$request = json_decode(file_get_contents('php://input'), true);
$action = $request['action'] ?? '';

try {
    switch ($action) {
        case 'start_project':
            $userRequest = $request['request'] ?? '';
            $outputType = $request['output_type'] ?? 'full_site';
            $framework = $request['framework'] ?? 'php';
            
            if (empty($userRequest)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project request is required']);
                exit;
            }
            
            // Enhanced request with framework and output type context
            $enhancedRequest = $userRequest . "\n\nOutput Type: " . $outputType . "\nFramework: " . $framework;
            
            $projectId = $autonomousSystem->startAutonomousProject($enhancedRequest, $outputType, $framework);
            
            echo json_encode([
                'success' => true,
                'project_id' => $projectId,
                'message' => 'Autonomous project started successfully',
                'output_type' => $outputType,
                'framework' => $framework
            ]);
            break;
            
        case 'process_tasks':
            $projectId = $request['project_id'] ?? '';
            if (empty($projectId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project ID is required']);
                exit;
            }
            
            // Process the task queue
            $processedTasks = $autonomousSystem->processTaskQueue();
            $projectStatus = $autonomousSystem->getProjectStatus($projectId);
            $taskQueue = $autonomousSystem->getTaskQueue();
            
            // Get recent communications
            $communications = $autonomousSystem->getAgentCommunication($projectId);
            $recentCommunications = array_slice($communications, -5); // Last 5 communications
            
            // Determine agent statuses based on current tasks
            $agentStatuses = [];
            $activeAgents = [];
            
            foreach ($taskQueue as $task) {
                if ($task['status'] === 'queued' && isset($task['data']['project_id']) && $task['data']['project_id'] === $projectId) {
                    $activeAgents[] = $task['agent'];
                }
            }
            
            // Set all agents to idle first
            $allAgents = ['project_manager', 'ui_designer', 'frontend_dev', 'backend_dev', 'content_strategist', 'qa_agent'];
            foreach ($allAgents as $agent) {
                $agentStatuses[$agent] = 'idle';
            }
            
            // Set active agents to working
            foreach ($activeAgents as $agent) {
                $agentStatuses[$agent] = 'working';
            }
            
            // Generate preview HTML from completed deliverables
            $previewHtml = generatePreviewFromProject($projectStatus, $processedTasks);
            
            // Generate code display
            $generatedCode = generateCodeDisplay($processedTasks);
            
            echo json_encode([
                'success' => true,
                'progress_percentage' => $projectStatus['progress_percentage'] ?? 0,
                'agent_statuses' => $agentStatuses,
                'new_communications' => $recentCommunications,
                'generated_code' => $generatedCode,
                'preview_html' => $previewHtml,
                'active_tasks' => getActiveTasks($taskQueue, $projectId),
                'has_pending_tasks' => count($taskQueue) > 0,
                'processed_tasks_count' => count($processedTasks)
            ]);
            break;
            
        case 'get_status':
            $projectId = $request['project_id'] ?? '';
            if (empty($projectId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project ID is required']);
                exit;
            }
            
            $projectStatus = $autonomousSystem->getProjectStatus($projectId);
            $communications = $autonomousSystem->getAgentCommunication($projectId);
            $taskQueue = $autonomousSystem->getTaskQueue();
            
            echo json_encode([
                'success' => true,
                'project_status' => $projectStatus,
                'recent_communications' => array_slice($communications, -3),
                'active_tasks' => getActiveTasks($taskQueue, $projectId)
            ]);
            break;
            
        case 'get_all_projects':
            $projects = $autonomousSystem->getAllActiveProjects();
            echo json_encode([
                'success' => true,
                'projects' => $projects
            ]);
            break;
            
        case 'export_project':
            $projectId = $request['project_id'] ?? '';
            if (empty($projectId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project ID is required']);
                exit;
            }
            
            $projectStatus = $autonomousSystem->getProjectStatus($projectId);
            $exportData = [
                'project' => $projectStatus['project'],
                'export_timestamp' => time(),
                'export_format' => 'joomlang_v1'
            ];
            
            echo json_encode([
                'success' => true,
                'export_data' => $exportData,
                'filename' => 'joomlang_project_' . $projectId . '.json'
            ]);
            break;
            
        case 'get_generated_code':
            $projectId = $request['project_id'] ?? 'default';
            $codeDisplay = generateCodeDisplay($projectId);
            echo json_encode(['success' => true, 'code' => $codeDisplay]);
            break;
            
        case 'save_project':
            $projectId = $request['project_id'] ?? '';
            $name = $request['name'] ?? null;
            if (empty($projectId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project ID is required']);
                exit;
            }
            
            $result = $autonomousSystem->saveProject($projectId, $name);
            echo json_encode($result);
            break;
            
        case 'load_saved_projects':
            $projects = $autonomousSystem->loadSavedProjects();
            echo json_encode(['success' => true, 'projects' => $projects]);
            break;
            
        case 'load_project':
            $projectId = $request['project_id'] ?? '';
            if (empty($projectId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project ID is required']);
                exit;
            }
            
            $result = $autonomousSystem->loadProject($projectId);
            echo json_encode($result);
            break;
            
        case 'infuse_project':
            $url = $request['url'] ?? '';
            $apiKey = $request['api_key'] ?? null;
            if (empty($url)) {
                http_response_code(400);
                echo json_encode(['error' => 'URL is required']);
                exit;
            }
            
            $result = $autonomousSystem->importFromJoomlaYooTheme($url, $apiKey);
            echo json_encode($result);
            break;
            
        case 'export_project_zip':
            $projectId = $request['project_id'] ?? '';
            if (empty($projectId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Project ID is required']);
                exit;
            }
            
            $result = $autonomousSystem->exportProject($projectId, 'zip');
            echo json_encode($result);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

function getActiveTasks($taskQueue, $projectId) {
    $activeTasks = [];
    
    foreach ($taskQueue as $task) {
        if (isset($task['data']['project_id']) && 
            $task['data']['project_id'] === $projectId && 
            $task['status'] === 'queued') {
            
            $activeTasks[] = [
                'agent' => ucwords(str_replace('_', ' ', $task['agent'])),
                'description' => $task['data']['description'] ?? ucwords(str_replace('_', ' ', $task['type'])),
                'priority' => $task['priority']
            ];
        }
    }
    
    return $activeTasks;
}

function generatePreviewFromProject($projectStatus, $processedTasks) {
    $html = '';
    
    // Look for design and frontend deliverables
    foreach ($processedTasks as $task) {
        if (isset($task['deliverable_type'])) {
            switch ($task['deliverable_type']) {
                case 'html_css_design':
                    if (isset($task['design_code'])) {
                        $html .= extractHTMLFromCode($task['design_code']);
                    }
                    break;
                case 'interactive_frontend':
                    if (isset($task['javascript_code'])) {
                        $html .= '<script>' . $task['javascript_code'] . '</script>';
                    }
                    break;
            }
        }
    }
    
    if (empty($html)) {
        $html = '<div style="text-align: center; padding: 40px; color: #999;">
                    <h3>🔨 Agents are working...</h3>
                    <p>Your autonomous agents are analyzing the request and creating the design.</p>
                 </div>';
    }
    
    return $html;
}

function extractHTMLFromCode($code) {
    // Try to extract HTML from the generated code
    if (strpos($code, '<html') !== false) {
        return $code;
    }
    
    // If it's just HTML fragments, wrap them
    if (strpos($code, '<') !== false) {
        return '<div style="padding: 20px;">' . $code . '</div>';
    }
    
    // If it's just text, format it nicely
    return '<div style="padding: 20px; background: #f8f9fa; border-radius: 8px; font-family: monospace;">' . 
           nl2br(htmlspecialchars($code)) . '</div>';
}

function generateCodeDisplay($projectId) {
    global $agentSystem;
    
    $codeOutput = '';
    $completedTasks = $agentSystem->getCompletedTasks($projectId);
    
    if (empty($completedTasks)) {
        return '<div style="text-align: center; padding: 40px; color: #999;">
                    <h3>🔨 Generating Code...</h3>
                    <p>Your autonomous agents are working on the project. Code will appear here as tasks are completed.</p>
                </div>';
    }
    
    foreach ($completedTasks as $task) {
        if (isset($task['result']['design_code'])) {
            $codeOutput .= "/* UI Design Code - " . ($task['agent'] ?? 'Agent') . " */\n";
            $codeOutput .= $task['result']['design_code'] . "\n\n";
        }
        if (isset($task['result']['javascript_code'])) {
            $codeOutput .= "/* JavaScript Code - " . ($task['agent'] ?? 'Agent') . " */\n";
            $codeOutput .= $task['result']['javascript_code'] . "\n\n";
        }
        if (isset($task['backend_output'])) {
            $codeOutput .= "// Backend Code\n" . $task['backend_output'] . "\n\n";
        }
    }
    
    if (empty($codeOutput)) {
        $codeOutput = "// Agents are generating code...\n// Check back in a moment to see the results.";
    }
    
    return htmlspecialchars($codeOutput);
}

?>