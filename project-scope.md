# MemberPress Stripe Price Updater - Project Scope

## Project Overview

Complete rewrite of a WordPress plugin for automating Stripe subscription price updates within MemberPress. Original plugin had significant flaws requiring complete architectural overhaul from WordPress options storage to proper database tables with job management.

## Key Problems Solved

- **Incorrect MemberPress Integration**: Fixed gateway access and subscription querying
- **Broken Stripe API Integration**: Implemented proper currency handling and error management  
- **Poor Job Management**: Fixed fundamental flaw where new jobs were created every 5 minutes instead of continuing existing jobs
- **Data Storage Issues**: Migrated from WordPress options to proper database tables with relationships
- **Security Vulnerabilities**: Added comprehensive SQL injection prevention and input validation

## Current Architecture

### Database Schema
- **Three-Table Design**: `mpsp_rules`, `mpsp_jobs`, `mpsp_entries` with proper relationships
- **Automatic Migration**: Seamless upgrade from legacy options-based installations
- **Manual Cascade Deletion**: Maintains data integrity across related tables

### Job Management System
- **Single-Job Execution**: Only one job runs at a time, enforced by database flags
- **Intelligent Continuation**: Jobs persist across cron cycles until completion (fixed critical flaw)
- **Conservative Processing**: 25 subscriptions per 5-minute cycle for server stability
- **Real-Time Progress**: Live updates with cancellation support

### Key Features
- **Multi-Gateway Support**: Handle multiple Stripe gateways with automatic API key selection
- **Advanced Filtering**: Price ranges, billing cycles, membership targeting with live preview
- **Professional Reporting**: CSV exports with user details, price changes, API mode tracking
- **Auto-Disable Rules**: Rules automatically disable when processing is complete
- **Email Notifications**: Automatic reports to MemberPress administrators

## Critical Technical Fixes

### Job Lifecycle Fix
**Previous (Incorrect)**:
- Cron Cycle 1: Create Job #1, process 25 subscriptions
- Cron Cycle 2: Create Job #2, process 25 more ❌

**Current (Correct)**:
- Cron Cycle 1: Create Job #1, process 25 subscriptions (status: running)  
- Cron Cycle 2: Continue Job #1, process 25 more (status: running)
- Cron Cycle 3: Continue Job #1, complete (status: completed)

### Security Hardening
- **SQL Injection Prevention**: All queries use prepared statements
- **Input Validation**: Comprehensive sanitization of user inputs
- **Permission Checks**: Verify `manage_options` capability for all actions

### MemberPress Integration
- **Native Stripe Requests**: Uses `MeprStripeGateway::send_stripe_request()`
- **Currency Compatibility**: Handles multi-currency with zero decimal support
- **Plan Reuse**: Leverages `MeprStripeGateway::get_stripe_plan_id()` to reduce API calls
- **Test Mode Detection**: Proper `MEMBERPRESS_STRIPE_TESTING` constant support

## Technical Status

**Current Plugin**: 2,774-line enterprise-grade WordPress plugin with:
- Responsive admin interface with real-time job monitoring
- Comprehensive error handling and logging
- Multi-currency support with proper formatting
- Automated cleanup and maintenance
- GitHub Actions CI/CD pipeline for releases

## File Structure
- **Main Plugin**: `/mp-stripe-price-updater.php` (single file, 2,774 lines)
- **Database Tables**: Rules → Jobs → Entries relationship
- **AJAX Handlers**: Real-time status updates and job management
- **Cron Integration**: 5-minute intervals with intelligent job continuation

## Development Standards
- WordPress coding standards compliance
- Singleton pattern implementation
- Comprehensive exception coverage
- Performance optimization with strategic indexing
- Future-proof integration patterns with MemberPress

## Recent Improvements

### Progress UI Enhancement  
- **Issue**: Job progress UI not showing after saving new rules (jobs only created by cron every 5 minutes)
- **Solution**: Immediate job initiation after rule creation
  - Modified `ajax_save_rule()` to return rule ID
  - Added `ajax_start_rule_processing()` endpoint  
  - Enhanced `start_new_job()` to accept dynamic subscription count
  - Added sessionStorage-based post-reload processing trigger
- **Flow**: Save rule → page refresh → auto-start job with 1 subscription → show progress UI → cron continues processing

## Key Learnings for Future Sessions
1. **Job Management**: Each rule represents one complete processing campaign, not multiple jobs
2. **Database Migration**: All operational data moved from `wp_options` to custom tables
3. **Critical Bug**: Fixed infinite job creation every 5 minutes vs proper job continuation
4. **Security**: All database operations use prepared statements
5. **Integration**: Leverage MemberPress native methods instead of custom implementations
6. **UI Responsiveness**: Immediate feedback requires post-save processing triggers, not blocking operations