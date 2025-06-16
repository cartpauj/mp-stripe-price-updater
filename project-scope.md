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
- **Three-Table Design**: 
  - `mpsp_rules`: Rule definitions with gateway targeting and price filters
  - `mpsp_jobs`: Job execution tracking with status and progress counters
  - `mpsp_entries`: Individual subscription processing records
- **Automatic Migration**: Seamless upgrade from legacy options-based installations
- **Proper Indexing**: Strategic indexes on key fields for performance
- **Data Integrity**: Foreign key relationships maintained manually

### Job Management System
- **Single-Job Execution**: Only one job runs at a time, enforced by `current_job` flag
- **Intelligent Continuation**: Jobs persist across cron cycles until completion
- **Conservative Processing**: 25 subscriptions per 5-minute cycle (configurable via filter)
- **Real-Time Progress**: Live AJAX updates with job cancellation support
- **Job States**: running, completed, cancelled, failed with proper state transitions

### Key Features
- **Multi-Gateway Support**: Handle multiple Stripe gateways with test/live mode detection
- **Advanced Filtering**: Price ranges/specific prices, billing cycles, membership targeting
- **Scalable Preview**: Count + sample display (15 entries) with CSV export option
- **Professional Reporting**: Multiple CSV export options for rules and job logs
- **Auto-Disable Rules**: Rules automatically disable when no matching subscriptions remain
- **Paginated Job Logs**: 50 entries/page with summary view and failed-only exports

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
- **Native Gateway Access**: Integrates directly with `MeprStripeGateway` instances
- **Currency Compatibility**: Handles multi-currency with zero decimal currency support
- **Stripe Price Management**: Uses MemberPress `get_stripe_plan_id()` method to create/retrieve Stripe prices
- **Subscription Updates**: Atomic process: Stripe API first, then MemberPress database, with `proration_behavior => 'none'`
- **Test Mode Detection**: Automatic detection of gateway test/live modes with UI indicators
- **Subscription Filtering**: Advanced querying of MemberPress subscriptions with status validation

## Technical Status

**Current Plugin**: 3,150+ line enterprise-grade WordPress plugin with:
- Responsive admin interface with real-time job monitoring
- Comprehensive error handling and logging
- Multi-currency support with proper formatting
- Automated cleanup and maintenance
- GitHub Actions CI/CD pipeline for releases

## File Structure
- **Main Plugin**: `/mp-stripe-price-updater.php` (single file, 3,150+ lines)
- **Database Tables**: Three-table design: `mpsp_rules`, `mpsp_jobs`, `mpsp_entries`
- **AJAX Handlers**: 15+ endpoints including CSV export and pagination
- **Cron Integration**: 5-minute intervals with intelligent job continuation
- **Version**: 1.2.1 with automatic database migration support

## Development Standards
- WordPress coding standards compliance
- Singleton pattern implementation
- Comprehensive exception coverage
- Performance optimization with strategic indexing
- Future-proof integration patterns with MemberPress

## Key Learnings for Future Sessions
1. **Job Management**: Each rule represents one complete processing campaign, not multiple jobs
2. **Database Migration**: All operational data moved from `wp_options` to custom tables
3. **Critical Bug**: Fixed infinite job creation every 5 minutes vs proper job continuation
4. **Security**: All database operations use prepared statements
5. **Integration**: Leverage MemberPress native methods instead of custom implementations
6. **UI Responsiveness**: Immediate feedback requires post-save processing triggers, not blocking operations
7. **Scalability**: Preview shows count + 15-sample, job logs paginated (50/page), CSV exports for large datasets
8. **PHP Compatibility**: Avoid array spread operators and null coalescing for older PHP versions
9. **Concurrency Control**: Single-job enforcement prevents race conditions; rules save as disabled during active jobs; Enable buttons auto-disable; zero-subscription rules rejected