# Quiz Alternating System Fix Plan

## Current Problem Analysis:
- Currently teams get different questions
- Teams should see the SAME question but only the active team can answer
- The other team should only watch/observe
- Questions should alternate between teams (A answers Q1, B answers Q2, A answers Q3, etc.)
- Total 17 questions, alternating between teams

## Required Changes:

### 1. Database Schema Changes:
- No changes needed to database structure
- Current `current_team` field in games table is sufficient

### 2. Backend Logic Changes (game_controller.php):
- Modify `nextQuestion()` method to properly alternate teams
- Ensure both teams see the same question
- Only allow the active team to submit answers
- Update scoring logic to work with alternating system

### 3. Frontend Logic Changes (quiz_main_page.html):
- Modify `displayQuestion()` function to show same question to both teams
- Only allow active team to interact with options
- Show clear indication of which team's turn it is
- Update UI to show "watching" state for non-active team

### 4. Game Flow Changes:
- Question 1: Team A answers, Team B watches
- Question 2: Team B answers, Team A watches  
- Question 3: Team A answers, Team B watches
- Continue alternating for 17 total questions
- Each team gets 8-9 questions (depending on who starts)

### 5. UI/UX Improvements:
- Clear visual indication of active team
- "Watching" message for non-active team
- Same question displayed to both teams
- Options only clickable for active team

## Implementation Steps:
1. Update backend nextQuestion logic
2. Update frontend displayQuestion logic
3. Update team turn indicators
4. Test alternating flow
5. Verify scoring works correctly
