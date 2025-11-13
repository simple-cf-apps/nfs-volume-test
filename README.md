# NFS Volume Test Application for Elastic Application Runtime

A Node.js application to test NFSv3 volume services in Elastic Application Runtime foundations.

## Features

- Test NFS volume mounting and access
- Write and read files from shared NFS storage
- Multi-instance testing to verify volume sharing
- REST API endpoints for volume operations

## Prerequisites

- Cloud Foundry CLI installed
- Access to an Elastic Application Runtime environment with NFS volume services enabled
- An NFS server with an exported share

## Deployment Steps

### 1. Create the NFS volume service instance
```bash
cf create-service nfs Existing nfs-volume \
  -c '{
    "share": "nfs://YOUR_NFS_SERVER/YOUR_EXPORT_PATH",
    "mount": "/var/vcap/data/nfs-test"
  }'
```

Replace `YOUR_NFS_SERVER` and `YOUR_EXPORT_PATH` with your actual NFS server details.

### 2. Wait for service to be ready
```bash
cf services
```

### 3. Push the application
```bash
cf push
```

The app will automatically bind to the service due to the manifest configuration.

### 4. Check the app status
```bash
cf app nfs-volume-test
```

## Testing the Application

### Get the application URL
```bash
APP_URL=$(cf app nfs-volume-test | grep routes | awk '{print $2}')
```

### Available Endpoints

- `GET /` - Check application and volume status
- `POST /write` - Write data to the volume
- `GET /read` - Read data from the volume
- `GET /files` - List all files in the volume

### Test Commands
```bash
# Check status
curl https://$APP_URL/

# Write data
curl -X POST https://$APP_URL/write \
  -H "Content-Type: application/json" \
  -d '{"message": "Test from first request"}'

# Read data
curl https://$APP_URL/read

# List files
curl https://$APP_URL/files
```

### Multi-Instance Testing
```bash
# Scale up to test multi-instance sharing
cf scale nfs-volume-test -i 3

# Write from different instances
for i in {1..10}; do
  curl -X POST https://$APP_URL/write \
    -H "Content-Type: application/json" \
    -H "X-CF-APP-INSTANCE: $((i % 3)):0" \
    -d "{\"message\": \"Test from request $i\"}"
done

# Read from any instance - should see all writes
curl https://$APP_URL/read
```

## Troubleshooting

- If the volume is not mounting, check the NFS service configuration
- Verify the NFS server is accessible from your Elastic Application Runtime deployment
- Check application logs: `cf logs nfs-volume-test --recent`