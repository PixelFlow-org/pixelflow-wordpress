import { Currency, NarrowButton, FaqIcon, Dropdown } from '@pixelflow-org/plugin-ui';
import PixelflowIcon from '@/shared/icons/pixelflow.icon.tsx';

type HeaderProps = {
  selectedCurrency: string;
  updateCurrency: (currency: string) => void;
};

const Header = ({ selectedCurrency, updateCurrency }: HeaderProps) => {
  return (
    <nav className="pf-header flex flex-row justify-between">
      <div className="pf-header__nav flex flex-row gap-2">
        <NarrowButton className="justify-center">
          <a
            className="text-xs !text-foreground flex gap-2"
            href="https://dashboard.pixelflow.so/dashboard/overview"
            target="_blank"
            rel="noopener noreferrer"
          >
            <PixelflowIcon />
            Go to Dashboard
          </a>
        </NarrowButton>
        <Dropdown.Root>
          <Dropdown.Trigger asChild>
            <NarrowButton>
              <FaqIcon /> Help & FAQ
            </NarrowButton>
          </Dropdown.Trigger>
          <Dropdown.Content>
            <Dropdown.Label>Help & FAQ</Dropdown.Label>
            <div className="flex !flex-col flex-start gap-3 text-xs">
              <a
                href="https://docs.pixelflow.so/"
                target="_blank"
                className="!text-foreground flex gap-1"
              >
                Help Docs
              </a>
              <a
                href="https://docs.pixelflow.so/articles/wordpress-setup-gj5fd"
                target="_blank"
                className="!text-foreground flex gap-1"
              >
                WordPress setup guide
              </a>
              <a
                href="https://docs.pixelflow.so/common-pixelflow-questions"
                target="_blank"
                className="s !text-foreground flex gap-1"
              >
                FAQ
              </a>
              <a
                href="https://docs.pixelflow.so/articles/how-to-track-website-elements-via-classes-cdrqg"
                target="_blank"
                className="!text-foreground flex gap-1"
              >
                Classes guide
              </a>
            </div>
          </Dropdown.Content>
        </Dropdown.Root>
      </div>
      <div className="pf-header__actions flex flex-row gap-2">
        <Currency selectedCurrency={selectedCurrency} updateCurrency={updateCurrency} />
      </div>
    </nav>
  );
};

export default Header;
