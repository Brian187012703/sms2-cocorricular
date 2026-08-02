#!/usr/bin/env python3
"""
SMS Python Data Processor Integration Script
Demonstrates token-authenticated REST API communication with the Laravel backend.
"""

import json
import os
import sys
import urllib.request
import urllib.parse

API_BASE_URL = os.getenv("SMS_API_URL", "http://localhost/sms/public/api")

def login(username, password):
    """Authenticate with Laravel Sanctum API and retrieve Bearer token."""
    url = f"{API_BASE_URL}/auth/login"
    payload = json.dumps({"username": username, "password": password}).encode('utf-8')
    req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json'})

    try:
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode())
            if data.get('success'):
                print(f"[+] Python Auth Success! Token obtained for user: {username}")
                return data.get('access_token')
    except Exception as e:
        print(f"[-] Python Auth Error: {e}")
        return None

def fetch_students(token):
    """Fetch student roster using Bearer token."""
    url = f"{API_BASE_URL}/students"
    req = urllib.request.Request(url, headers={'Authorization': f'Bearer {token}'})

    try:
        with urllib.request.urlopen(req) as response:
            data = json.loads(response.read().decode())
            print(f"[+] Retrived Student Roster ({len(data.get('data', {}).get('data', []))} records)")
            return data
    except Exception as e:
        print(f"[-] Python Fetch Error: {e}")
        return None

if __name__ == "__main__":
    print("=== SMS Python REST API Data Integration Tool ===")
    token = login("admin", "Admin@1234")
    if token:
        students = fetch_students(token)
        print("Integration Status: OK")
