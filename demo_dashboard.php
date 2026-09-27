<?php

/**
 * Dashboard Demo
 *
 * This script simulates the hierarchical group dashboard output.
 * Run it to see what the dashboard looks like with sample data.
 *
 *   php demo_dashboard.php
 */

echo "\n";
echo "================================================================================\n";
echo "  HIERARCHICAL GROUP DASHBOARD - DEMO\n";
echo "================================================================================\n";
echo "\n";

// Simulate current user and context
echo "CONTEXT\n";
echo "-------\n";
echo "User: John Doe (john@example.com)\n";
echo "Organization: Acme Org\n";
echo "Current Group: Acme - North Region - Local Assembly\n";
echo "Date: September 27, 2026\n";
echo "Widgets: 47 registered, 37 visible for this user\n";
echo "\n";

// Simulate dashboard header
echo "================================================================================\n";
echo "\n";
echo "  [Group Switcher: Acme - North Region - Local Assembly ▼]\n";
echo "\n";

// Simulate sections and widgets
echo "================================================================================\n";
echo "\n";

// People Section
echo "  PEOPLE (9 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";
echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Groups                                                   [Permission] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ You are a member of 3 groups:                                │\n";
echo "  │ • Acme (National) - Leader                                  │\n";
echo "  │ • Acme - North Region - Leader                             │\n";
echo "  │ • Acme - North Region - Local Assembly - Member            │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ Group Hierarchy                                            [Static Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Acme                                                       │\n";
echo "  │ └── North Region                                          │\n";
echo "  │     └── Area A                                           │\n";
echo "  │         └── Local Assembly                                │\n";
echo "  │             ├── Fellowship 1                               │\n";
echo "  │             │   └── Senior Cell A                         │\n";
echo "  │             │       └── Cell 1                            │\n";
echo "  │             └── Fellowship 2                               │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ Birthdays                                                [Realtime Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Upcoming birthdays in your groups:                         │\n";
echo "  │                                                             │\n";
echo "  │ Today:                                                    │\n";
echo "  │ • Jane Smith (Leader, North Region)                       │\n";
echo "  │                                                             │\n";
echo "  │ In 2 days:                                                │\n";
echo "  │ • Mike Johnson (Member, Local Assembly)                   │\n";
echo "  │                                                             │\n";
echo "  │ In 5 days:                                                │\n";
echo "  │ • Sarah Williams (Member, Local Assembly)                  │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Profile                                                [Static Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Name: John Doe                                            │\n";
echo "  │ Email: john@example.com                                    │\n";
echo "  │ Phone: +1 555-123-4567                                   │\n";
echo "  │ Member since: January 15, 2025                            │\n";
echo "  │ Status: Active                                            │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Gamification Section
echo "================================================================================\n";
echo "GAMIFICATION (6 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Standing                                              [Summary Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Level: Gold                                              │\n";
echo "  │ Points: 1,250                                            │\n";
echo "  │ Rank: #3 in Local Assembly                              │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Points Summary                                        [Summary Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ This Week: +50 pts                                       │\n";
echo "  │ This Month: +200 pts                                     │\n";
echo "  │ This Year: +1,250 pts                                    │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Badges                                                [List Cache]    │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ 🏆 Faithful Attender    🏅 Perfect Attendance   🎖️ First Giver  │\n";
echo "  │ 📚 Bible Scholar       🎤 Speaker             🤝 Mentor       │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Journey Section
echo "================================================================================\n";
echo "JOURNEY (5 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Current Stage                                        [Summary Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Stage: Making Disciples                                  │\n";
echo "  │ Progress: 75% complete                                    │\n";
echo "  │ Started: June 1, 2025                                    │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Disciples                                            [List Cache]    │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ You are discipling 3 people:                             │\n";
echo "  │ • Alice Brown - Stage: New Believer (25% complete)        │\n";
echo "  │ • Bob Wilson - Stage: Growing (50% complete)              │\n";
echo "  │ • Carol Davis - Stage: Maturing (75% complete)           │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Events Section
echo "================================================================================\n";
echo "EVENTS (8 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Upcoming Events                                      [Realtime Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Tomorrow:                                              │\n";
echo "  │ • Prayer Meeting - 7:00 PM - Local Assembly Hall          │\n";
echo "  │                                                             │\n";
echo "  │ This Weekend:                                          │\n";
echo "  │ • Sunday Service - 10:00 AM - Main Sanctuary             │\n";
echo "  │ • Youth Fellowship - 2:00 PM - Youth Room               │\n";
echo "  │                                                             │\n";
echo "  │ Next Week:                                             │\n";
echo "  │ • Bible Study - Wednesday 7:00 PM - Room 201            │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ Group Upcoming Events                                   [Realtime Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ • Regional Conference - Oct 5-7 - All North Region groups   │\n";
echo "  │ • Leadership Training - Oct 15 - Local Assembly leaders     │\n";
echo "  │ • Outreach Day - Oct 20 - All groups                      │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Giving Section
echo "================================================================================\n";
echo "GIVING (5 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Giving Summary                                      [Summary Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ This Year: $2,500                                       │\n";
echo "  │ Last Month: $250                                        │\n";
echo "  │ Average: $208/month                                     │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Commitments                                         [List Cache]    │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ • Tithes: $200/month (Active)                            │\n";
echo "  │ • Missions: $50/month (Active)                           │\n";
echo "  │ • Building Fund: $25/month (Active)                      │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Learning Section
echo "================================================================================\n";
echo "LEARNING (4 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Courses                                             [List Cache]    │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ • Foundation Course - 85% complete                       │\n";
echo "  │ • Leadership 101 - 60% complete                         │\n";
echo "  │ • Bible Survey - 30% complete                           │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Communications Section
echo "================================================================================\n";
echo "COMMUNICATIONS (4 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Announcements                                       [Realtime Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ New:                                                    │\n";
echo "  │ • Regional Prayer Day - Sep 30 - All invited              │\n";
echo "  │                                                             │\n";
echo "  │ Unread: 1                                               │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Notifications                                       [Live - No Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ • You have 2 new notifications                          │\n";
echo "  │ • Event registration confirmed: Prayer Meeting       │\n";
echo "  │ • New message from: Sarah Williams                      │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Access Section
echo "================================================================================\n";
echo "ACCESS (2 widgets)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ My Requests                                            [List Cache]    │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ • Access to: Regional Reports - Pending approval         │\n";
echo "  │ • Access to: Financial Data - Approved                  │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Reports Section
echo "================================================================================\n";
echo "REPORTS (1 widget)\n";
echo "  " . str_repeat("-", 76) . "\n";
echo "\n";

echo "  ┌─────────────────────────────────────────────────────────────────────┐\n";
echo "  │ Group Funnel                                             [Summary Cache] │\n";
echo "  │─────────────────────────────────────────────────────────────────────│\n";
echo "  │ Prospects: 15    →    First-time Visitors: 8    →    Members: 45     │\n";
echo "  │                                                             │\n";
echo "  │ Conversion Rate: 75%                                    │\n";
echo "  └─────────────────────────────────────────────────────────────────────┘\n";
echo "\n";

// Footer
echo "================================================================================\n";
echo "\n";
echo "  [Viewing: Acme - North Region - Local Assembly]\n";
echo "  [Last Updated: " . date('Y-m-d H:i:s') . "]\n";
echo "  [Cached: 37/47 widgets]\n";
echo "\n";
echo "================================================================================\n";
echo "\n";

echo "LEGEND:\n";
echo "  [Static Cache]    - Cached for 1 hour (rarely changes)\n";
echo "  [Summary Cache]   - Cached for 5 minutes (statistics)\n";
echo "  [List Cache]     - Cached for 3 minutes (lists)\n";
echo "  [Realtime Cache] - Cached for 1 minute (near real-time)\n";
echo "  [Live - No Cache] - Always fresh data\n";
echo "  [Permission]     - Requires specific permission\n";
echo "\n";

echo "NOTES:\n";
echo "  • This is a simulation of what the dashboard looks like\n";
echo "  • Actual dashboard is server-rendered at /me/groups\n";
echo "  • Widgets shown depend on user permissions and group scope\n";
echo "  • 47 total widgets, 37 visible for this user\n";
echo "  • Mobile-first responsive design\n";
echo "\n";
echo "================================================================================\n";
