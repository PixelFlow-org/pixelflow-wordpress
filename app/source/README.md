# wordpress

**PixelFlow plugin for WordPress** - Custom Template

---

## 🚀 Getting Started

### Prerequisites

- Node.js 18+ or 20+
- pnpm (recommended), npm, or yarn
- GitHub Packages access token with `read:packages` scope

### Step 1: Configure GitHub Packages

Add to your project `.npmrc`:

```ini
@pixelflow-org:registry=https://npm.pkg.github.com
registry=https://registry.npmjs.org/
```

Add to your user `~/.npmrc`:

```ini
//npm.pkg.github.com/:_authToken=YOUR_GITHUB_PAT
```

### Step 2: Install Dependencies

```bash
pnpm install
```

### Step 3: Set Up Environment Variables

Create a `.env` file:

```bash
cp .env.example .env
```

Edit `.env` with your configuration:

```env
VITE_API_BASE_URL=https://api.pixelflow.com
VITE_UI_BASE_URL=https://app.pixelflow.com
VITE_CDN_URL=https://cdn.pixelflow.com/script.js
```

### Step 4: Implement Platform Adapter

Open `src/adapters/wordpress-adapter.ts` and implement the required methods:

```typescript
export class WordpressAdapter implements PlatformAdapter {
  async injectScript(script: string): Promise<void> {
    // TODO: Implement script injection
  }

  async getSiteId(): Promise<string> {
    // TODO: Return unique site identifier
  }

  showNotification(message: string, type: 'success' | 'error' | 'info' | 'warning'): void {
    // TODO: Show notifications
  }

  getTheme(): 'light' | 'dark' {
    // TODO: Detect theme
  }

  // ... implement remaining methods
}
```

### Step 5: Configure Platform ID

Update `src/config/platform.config.ts`:

```typescript
platformId: YOUR_PLATFORM_ID_HERE, // ⚠️ Update this
```

### Step 6: Start Development

```bash
pnpm dev
```

---

## 📋 Implementation Checklist

### Platform Adapter (Required)

- [ ] Implement `injectScript()` method
- [ ] Implement `removeScript()` method
- [ ] Implement `getSiteId()` method
- [ ] Implement `showNotification()` method
- [ ] Implement `getTheme()` method
- [ ] Implement `onThemeChange()` method

### Configuration (Required)

- [ ] Set correct platform ID in `platform.config.ts`
- [ ] Configure environment variables in `.env`
- [ ] Test API connectivity

### Selected Features


- [ ] ✅ Authentication - Included and ready to use



- [ ] ✅ Pixel Management - Included and ready to use


- [ ] ✅ Event Tracking - Included and ready to use


- [ ] ✅ Sites Management - Included and ready to use


---

## ✨ Features

Your custom plugin includes:


- **Authentication** - User login and session management



- **Pixel Management** - Add, edit, delete Meta Pixels


- **Event Tracking** - Monitor and filter events


- **Sites Management** - Manage multiple sites


---

## 📚 Resources

- [`@pixelflow-org/plugin-core`](../../packages/pixelflow-plugin-core) - Core logic
- [`@pixelflow-org/plugin-ui`](../../packages/pixelflow-plugin-ui) - UI components
- [`@pixelflow-org/plugin-features`](../../packages/pixelflow-plugin-features) - Feature modules

---

## 📜 Scripts

| Script         | Description            |
| -------------- | ---------------------- |
| `pnpm dev`     | Start dev server       |
| `pnpm build`   | Build for production   |
| `pnpm lint`    | Check code quality     |
| `pnpm format`  | Format code            |

---

## 📝 License

Private - © PixelFlow Organization 2025
