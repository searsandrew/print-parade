using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text;
using System.Text.Json;
using PrintParade.Bridge.Core;

namespace PrintParade.Bridge.Windows;

[SupportedOSPlatform("windows")]
public sealed partial class WindowsBridgeConfigurationStore
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true,
    };

    public static string DefaultPath => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "Print Parade",
        "bridge.json");

    public async Task<BridgeConfiguration> LoadAsync(
        string? path = null,
        CancellationToken cancellationToken = default)
    {
        EnsureWindows();
        var configurationPath = path ?? DefaultPath;

        StoredConfiguration stored;

        try
        {
            await using var stream = File.OpenRead(configurationPath);
            stored = await JsonSerializer.DeserializeAsync<StoredConfiguration>(stream, JsonOptions, cancellationToken)
                ?? throw new InvalidDataException("The bridge configuration file is empty.");
        }
        catch (JsonException exception)
        {
            throw new InvalidDataException("The bridge configuration file contains invalid JSON.", exception);
        }

        if (!Uri.TryCreate(stored.ServerUrl, UriKind.Absolute, out var serverUrl))
        {
            throw new InvalidDataException("The bridge configuration contains an invalid server URL.");
        }

        return new BridgeConfiguration(serverUrl, Unprotect(stored.ProtectedBridgeToken));
    }

    public async Task SaveAsync(
        BridgeConfiguration configuration,
        string? path = null,
        CancellationToken cancellationToken = default)
    {
        EnsureWindows();
        ArgumentException.ThrowIfNullOrWhiteSpace(configuration.BridgeToken);
        var configurationPath = path ?? DefaultPath;
        var directory = Path.GetDirectoryName(configurationPath)
            ?? throw new InvalidOperationException("The bridge configuration path has no parent directory.");
        Directory.CreateDirectory(directory);

        var stored = new StoredConfiguration(
            configuration.ServerUrl.ToString(),
            Protect(configuration.BridgeToken));
        var temporaryPath = configurationPath + ".tmp";

        await using (var stream = File.Create(temporaryPath))
        {
            await JsonSerializer.SerializeAsync(stream, stored, JsonOptions, cancellationToken);
        }

        File.Move(temporaryPath, configurationPath, true);
    }

    private static string Protect(string value)
    {
        var bytes = Encoding.UTF8.GetBytes(value);
        var input = DataBlob.FromBytes(bytes);

        try
        {
            if (!CryptProtectData(
                ref input,
                IntPtr.Zero,
                IntPtr.Zero,
                IntPtr.Zero,
                IntPtr.Zero,
                ProtectionFlags.LocalMachine | ProtectionFlags.UiForbidden,
                out var output))
            {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Windows could not protect the bridge token.");
            }

            return Convert.ToBase64String(output.ToBytesAndFree());
        }
        finally
        {
            input.Free();
        }
    }

    private static string Unprotect(string protectedValue)
    {
        byte[] protectedBytes;

        try
        {
            protectedBytes = Convert.FromBase64String(protectedValue);
        }
        catch (FormatException exception)
        {
            throw new InvalidDataException("The protected bridge token is invalid.", exception);
        }

        var input = DataBlob.FromBytes(protectedBytes);

        try
        {
            if (!CryptUnprotectData(
                ref input,
                IntPtr.Zero,
                IntPtr.Zero,
                IntPtr.Zero,
                IntPtr.Zero,
                ProtectionFlags.UiForbidden,
                out var output))
            {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Windows could not unprotect the bridge token.");
            }

            return Encoding.UTF8.GetString(output.ToBytesAndFree());
        }
        finally
        {
            input.Free();
        }
    }

    private static void EnsureWindows()
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("Bridge configuration protection is available only on Windows.");
        }
    }

    [LibraryImport("crypt32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static partial bool CryptProtectData(
        ref DataBlob input,
        IntPtr description,
        IntPtr optionalEntropy,
        IntPtr reserved,
        IntPtr prompt,
        ProtectionFlags flags,
        out DataBlob output);

    [LibraryImport("crypt32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static partial bool CryptUnprotectData(
        ref DataBlob input,
        IntPtr description,
        IntPtr optionalEntropy,
        IntPtr reserved,
        IntPtr prompt,
        ProtectionFlags flags,
        out DataBlob output);

    [LibraryImport("kernel32.dll")]
    private static partial IntPtr LocalFree(IntPtr memory);

    [Flags]
    private enum ProtectionFlags : uint
    {
        LocalMachine = 0x1,
        UiForbidden = 0x4,
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct DataBlob
    {
        internal int Size;
        internal IntPtr Data;

        internal static DataBlob FromBytes(byte[] bytes)
        {
            var data = Marshal.AllocHGlobal(bytes.Length);
            Marshal.Copy(bytes, 0, data, bytes.Length);

            return new DataBlob { Size = bytes.Length, Data = data };
        }

        internal readonly byte[] ToBytesAndFree()
        {
            try
            {
                var bytes = new byte[Size];
                Marshal.Copy(Data, bytes, 0, Size);

                return bytes;
            }
            finally
            {
                LocalFree(Data);
            }
        }

        internal void Free()
        {
            if (Data != IntPtr.Zero)
            {
                Marshal.FreeHGlobal(Data);
                Data = IntPtr.Zero;
                Size = 0;
            }
        }
    }

    private sealed record StoredConfiguration(string ServerUrl, string ProtectedBridgeToken);
}
