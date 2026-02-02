#!/bin/bash
# ===========================================
# Manual Deployment Script for Cloud Run
# Use when Cloud Build fails
# ===========================================

set -e  # Exit on error

echo "🚀 MANUAL DEPLOYMENT SCRIPT"
echo "============================"
echo ""

# Check if logged in
echo "🔍 Checking gcloud authentication..."
gcloud auth list --filter=status:ACTIVE --format="value(account)" | head -1

if [ $? -ne 0 ]; then
    echo "❌ Not authenticated. Please run:"
    echo "   gcloud auth login"
    exit 1
fi

# Set project
echo ""
echo "📋 Setting project..."
gcloud config set project project-7be1cae5-5f08-45fb-aca

# Deploy
echo ""
echo "🚀 Deploying to Cloud Run..."
echo "This may take 5-10 minutes..."
echo ""

gcloud run deploy crypto-asteroid \
  --source . \
  --region=us-central1 \
  --platform=managed \
  --allow-unauthenticated \
  --set-env-vars="MYSQLHOST=127.0.0.1,MYSQLPORT=3306,MYSQLDATABASE=unobix_db,MYSQLUSER=unobix_user,MYSQLPASSWORD=YyZD3H)dndSo*A/N,FIREBASE_API_KEY=AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U,FIREBASE_PROJECT_ID=unobix-oauth-a69cd,ADMIN_PASSWORD=Admin@Unobix2024!,GAME_SECRET_KEY=unobix_production_secret_key_2024_change_me,APP_ENV=production,DEBUG=false" \
  --add-cloudsql-instances="project-7be1cae5-5f08-45fb-aca:us-central1:unobix"

echo ""
echo "✅ DEPLOY COMPLETE!"
echo ""
echo "📊 Service URL: https://crypto-asteroid-234282032979.us-central1.run.app"
echo "🔧 phpMyAdmin: https://crypto-asteroid-234282032979.us-central1.run.app/phpmyadmin"
echo "🔑 Admin password: Admin@Unobix2024!"
echo ""
echo "📋 To check logs:"
echo "   gcloud logging read \"resource.type=cloud_run_revision\" --limit=10"
echo ""
echo "🎉 Done!"